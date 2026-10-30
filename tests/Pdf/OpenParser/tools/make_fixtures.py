#!/usr/bin/env python3
"""
Regenerates tests/fixtures/*.pdf, the golden object dumps (tests/fixtures/golden/*.json) and manifest.json.

The golden dumps come from an independent implementation (qpdf, through pikepdf), so the PHP tests compare the
objects read by this library against what qpdf reads from the very same files.

Requirements: python3, reportlab, pillow, pikepdf, qpdf, ghostscript (optional: pymupdf).
Usage: python3 tests/tools/make_fixtures.py
"""
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
from decimal import Decimal

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import pdfwriter as w  # noqa: E402

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
FIX = os.path.join(ROOT, "fixtures")
GOLD = os.path.join(FIX, "golden")
TMP = os.path.join(ROOT, ".tmp")


def run(*cmd):
    subprocess.run(cmd, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


# --------------------------------------------------------------------------------------------------------------------
# base document
# --------------------------------------------------------------------------------------------------------------------
def make_rich(path):
    from PIL import Image
    from reportlab.lib.pagesizes import A4, landscape, letter
    from reportlab.pdfbase import pdfmetrics
    from reportlab.pdfbase.ttfonts import TTFont
    from reportlab.pdfgen import canvas

    font_name = "Helvetica"
    for candidate in ("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
                      "/usr/share/fonts/dejavu/DejaVuSans.ttf"):
        if os.path.exists(candidate):
            pdfmetrics.registerFont(TTFont("DejaVu", candidate))
            font_name = "DejaVu"
            break

    # raster images: a flate image and a DCT (JPEG) image
    img = Image.new("RGB", (96, 64))
    for x in range(96):
        for y in range(64):
            img.putpixel((x, y), (x * 255 // 95, y * 255 // 63, (x * y) % 256))
    png_path = os.path.join(TMP, "grad.png")
    jpg_path = os.path.join(TMP, "grad.jpg")
    img.save(png_path)
    img.save(jpg_path, quality=85)

    c = canvas.Canvas(path, pagesize=A4, pageCompression=1)
    c.setTitle("FPDI open parser fixture")
    c.setAuthor("fixture generator")

    # page 1: text, vector graphics, images, links, bookmarks
    c.bookmarkPage("p1")
    c.addOutlineEntry("Page one", "p1", level=0)
    c.setFont(font_name, 22)
    c.drawString(72, 780, "Rich fixture - page 1 (åäö ŝ ł ü)")
    c.setFont("Times-Roman", 11)
    for i in range(35):
        c.drawString(72, 740 - i * 13, "Line %d: the quick brown fox jumps over the lazy dog 0123456789" % i)
    c.setStrokeColorRGB(0.8, 0.1, 0.1)
    c.setFillColorRGB(0.9, 0.9, 0.3)
    c.rect(60, 60, 300, 100, fill=1)
    c.bezier(380, 80, 420, 160, 460, 20, 520, 140)
    c.drawImage(png_path, 380, 400, width=160, height=107)
    c.drawImage(jpg_path, 380, 280, width=160, height=107)
    c.linkURL("https://example.com/", (72, 770, 300, 800), relative=0)
    c.showPage()

    # page 2: landscape letter, rotated by 90 degrees
    c.setPageSize(landscape(letter))
    c.bookmarkPage("p2")
    c.addOutlineEntry("Page two", "p2", level=0)
    c.setPageRotation(90)
    c.setFont("Helvetica-Bold", 28)
    c.drawString(72, 500, "Rich fixture - page 2 (rotated)")
    c.setFont("Courier", 10)
    for i in range(20):
        c.drawString(72, 460 - i * 12, "def f%d(x): return x * %d  # courier" % (i, i))
    c.circle(500, 200, 80, fill=0)
    c.showPage()

    # page 3: letter portrait with a crop box
    c.setPageSize(letter)
    c.setPageRotation(0)
    if hasattr(c, "setCropBox"):
        c.setCropBox((30, 30, 580, 760))
    c.bookmarkPage("p3")
    c.addOutlineEntry("Page three", "p3", level=0)
    c.setFont("Helvetica", 14)
    c.drawString(72, 700, "Rich fixture - page 3 (crop box)")
    c.setLineWidth(3)
    c.line(40, 40, 570, 750)
    c.showPage()
    c.save()


# --------------------------------------------------------------------------------------------------------------------
# helpers for byte level derivations
# --------------------------------------------------------------------------------------------------------------------
def startxref_of(data: bytes) -> int:
    i = data.rindex(b"startxref")
    return int(data[i + 9:].split()[0])


def incremental_xref_stream(base: bytes, new_objects: dict, free=(), size=None, root=1, info=None,
                            widths=(1, 3, 1), trailer_prev=True) -> bytes:
    """Append an incremental update with a cross-reference *stream*. new_objects: {num: object body}."""
    prev = startxref_of(base)
    b = w.Builder(header=b"", prefix=b"")
    b.buf = bytearray(base)
    if not base.endswith(b"\n"):
        b.buf += b"\n"
    for num, body in sorted(new_objects.items()):
        b.obj(num, body)
    xref_num = max(list(new_objects) + list(free) + [size or 0]) + 1 if size is None else size
    size = xref_num + 1
    entries = {n: (1, off, 0) for n, off in b.offsets.items()}
    for n in free:
        entries[n] = (0, 0, 1)
    xref_pos = len(b.buf)
    entries[xref_num] = (1, xref_pos, 0)
    nums = sorted(entries)
    index = []
    run_start = prev_n = None
    runs = []
    for n in nums:
        if runs and runs[-1][-1] == n - 1:
            runs[-1].append(n)
        else:
            runs.append([n])
    for r in runs:
        index += [r[0], len(r)]
    ents = [entries[n] for r in runs for n in r]
    trailer = b" /Root %d 0 R" % root
    if info:
        trailer += b" /Info %d 0 R" % info
    b.xref_stream(xref_num, ents, widths, trailer, size, index=index, prev=prev if trailer_prev else None,
                  predictor="png")
    return b.finish(xref_pos)


def incremental_classic(base: bytes, new_objects: dict, size: int, root=1, info=None) -> bytes:
    prev = startxref_of(base)
    b = w.Builder(header=b"", prefix=b"")
    b.buf = bytearray(base)
    if not base.endswith(b"\n"):
        b.buf += b"\n"
    for num, body in sorted(new_objects.items()):
        b.obj(num, body)
    trailer = b" /Root %d 0 R" % root
    if info:
        trailer += b" /Info %d 0 R" % info
    pos = b.classic_xref(dict(b.offsets), trailer, size, prev=prev, fill_free=False)
    return b.finish(pos)


# --------------------------------------------------------------------------------------------------------------------
# golden dumps (canonical JSON of every object, as seen by qpdf)
# --------------------------------------------------------------------------------------------------------------------
def canon(obj, top=False):
    import pikepdf

    if not top and isinstance(obj, pikepdf.Object) and obj.is_indirect:
        return {"r": [obj.objgen[0], obj.objgen[1]]}
    if obj is None:
        return None
    if isinstance(obj, bool):
        return obj
    if isinstance(obj, int):
        return obj
    if isinstance(obj, (float, Decimal)):
        return float(obj)
    if isinstance(obj, pikepdf.Stream):
        d = {str(k)[1:]: canon(v) for k, v in obj.items()}
        return {"stream": {"dict": d, "sha1": hashlib.sha1(obj.read_raw_bytes()).hexdigest()}}
    if isinstance(obj, pikepdf.Name):
        return str(obj)
    if isinstance(obj, pikepdf.String):
        return {"s": bytes(obj).hex()}
    if isinstance(obj, pikepdf.Array):
        return [canon(x) for x in obj]
    if isinstance(obj, pikepdf.Dictionary):
        return {str(k)[1:]: canon(v) for k, v in obj.items()}
    raise TypeError(type(obj))


def golden_dump(path):
    import pikepdf

    objects = {}
    # pikepdf hands scalar indirect objects (e.g. an indirect /Length) out as plain Python numbers without their
    # object id, so the list of object numbers comes from qpdf's cross-reference listing.
    shown = subprocess.run(["qpdf", "--show-xref", path], capture_output=True, text=True).stdout
    numbers = {}
    for line in shown.splitlines():
        if "compressed" not in line:  # free entries are listed without a location
            continue
        num = int(line.split("/", 1)[0])
        numbers[num] = int(line.split("offset = ")[1]) if "offset = " in line else None
    raw = open(path, "rb").read()
    header = raw.index(b"%PDF-")
    with pikepdf.open(path) as pdf:
        for num in sorted(numbers):
            if num == 0:
                continue
            obj = pdf.get_object((num, 0))
            if obj is None and numbers[num] is not None:
                # qpdf cannot resolve some scalar objects while it reads the cross-reference (e.g. the indirect
                # /Length of a cross-reference stream) - take the value from the file itself
                m = re.match(rb"\d+\s+\d+\s+obj\s*([-+0-9.]+)\s", raw[header + numbers[num]:header + numbers[num] + 64])
                if m:
                    obj = float(m.group(1)) if b"." in m.group(1) else int(m.group(1))
            if isinstance(obj, pikepdf.Stream):
                t = obj.get("/Type")
                if t is not None and str(t) in ("/ObjStm", "/XRef"):
                    continue
            objects[str(num)] = canon(obj, top=True)
        trailer = {}
        for k in ("/Root", "/Info"):
            if k in pdf.trailer:
                trailer[k[1:]] = [pdf.trailer[k].objgen[0], pdf.trailer[k].objgen[1]]
        pages = []
        for page in pdf.pages:
            box = [float(x) for x in page.cropbox]
            rot = int(page.obj.get("/Rotate", 0)) % 360
            wd, ht = box[2] - box[0], box[3] - box[1]
            if rot in (90, 270):
                wd, ht = ht, wd
            pages.append([round(wd, 3), round(ht, 3)])
    return {"objects": objects, "trailer": trailer, "pages": pages}


# --------------------------------------------------------------------------------------------------------------------
def main():
    for d in (FIX, GOLD, TMP):
        shutil.rmtree(d, ignore_errors=True) if d == GOLD else None
        os.makedirs(d, exist_ok=True)

    manifest = []

    def add(name, data=None, note="", valid=True, golden_from=None, importable=True):
        path = os.path.join(FIX, name)
        if data is not None:
            with open(path, "wb") as f:
                f.write(data)
        source = golden_from or name
        g = golden_dump(os.path.join(FIX, source))
        gname = os.path.splitext(source)[0] + ".json"
        with open(os.path.join(GOLD, gname), "w") as f:
            json.dump(g, f, sort_keys=True)
        manifest.append({"file": name, "golden": gname, "note": note, "pages": len(g["pages"]),
                         "sizes": g["pages"], "importable": importable})

    # --- tool generated -------------------------------------------------------------------------------------------
    rich = os.path.join(FIX, "rich-classic.pdf")
    make_rich(rich)
    add("rich-classic.pdf", note="reportlab, PDF 1.4 style classic cross-reference table")

    run("qpdf", "--object-streams=generate", "--compress-streams=y", "--recompress-flate", rich,
        os.path.join(FIX, "rich-qpdf-objstm.pdf"))
    add("rich-qpdf-objstm.pdf", note="qpdf: PDF 1.5, xref stream (PNG predictor) + object streams")

    run("qpdf", "--linearize", "--object-streams=generate", rich, os.path.join(FIX, "rich-qpdf-linearized.pdf"))
    add("rich-qpdf-linearized.pdf", note="qpdf: linearized, xref streams, object streams")

    run("qpdf", "--object-streams=generate", "--force-version=1.7", "--min-version=1.7", rich,
        os.path.join(FIX, "rich-qpdf-1.7.pdf"))
    add("rich-qpdf-1.7.pdf", note="qpdf: declared as PDF 1.7")

    if shutil.which("gs"):
        run("gs", "-sDEVICE=pdfwrite", "-dCompatibilityLevel=1.7", "-dNOPAUSE", "-dBATCH", "-dQUIET",
            "-dAutoRotatePages=/None", "-sOutputFile=" + os.path.join(FIX, "rich-gs-1.7.pdf"), rich)
        add("rich-gs-1.7.pdf", note="Ghostscript pdfwrite, PDF 1.7")

    try:
        import pymupdf as fitz  # PyMuPDF

        doc = fitz.open(rich)
        doc.save(os.path.join(FIX, "rich-mupdf-objstm.pdf"), garbage=3, deflate=True, use_objstms=1)
        add("rich-mupdf-objstm.pdf", note="MuPDF writer: xref stream + object streams")
    except Exception as e:  # optional
        print("skipping MuPDF fixture:", e)

    # --- hand crafted ---------------------------------------------------------------------------------------------
    add("hand-xref-std.pdf", w.doc_xref_stream(), note="/W [1 2 1], PNG Up predictor, one object stream")
    add("hand-xref-split-index.pdf", w.doc_xref_stream(index_split=True), note="/Index with two subsections")
    add("hand-xref-w0.pdf", w.doc_xref_stream(widths=(0, 3, 0), objstm=False, skip_zero=True, predictor=None),
        note="/W [0 3 0]: type field absent (defaults to 1), no generation field, no predictor")
    add("hand-xref-tiff.pdf", w.doc_xref_stream(predictor="tiff", widths=(1, 3, 1)), note="TIFF predictor 2")
    add("hand-filter-chain.pdf", w.doc_xref_stream(chain=("flate", "ahx"), objstm_chain=("flate", "a85")),
        note="xref stream: Flate+ASCIIHex, object stream: Flate+ASCII85")
    add("hand-png-filters.pdf", w.doc_png_many_objects(), note="400 rows with PNG filters None/Sub/Up/Avg/Paeth")
    add("hand-indirect-length.pdf", w.doc_xref_stream(length_ref=True),
        note="xref stream whose /Length is an indirect reference")
    add("hand-hybrid.pdf", w.doc_hybrid(), note="hybrid-reference file (classic table + /XRefStm)")
    add("hand-hybrid-free-entries.pdf", w.doc_hybrid(hidden_as_free=True),
        note="hybrid-reference file whose table lists the hidden objects as free entries",
        golden_from="hand-hybrid.pdf")

    base = w.doc_xref_stream(pages=2)
    # object numbers of doc_xref_stream(pages=2): 1 catalog, 2 pages, 3/4 page 1 + content, 5/6 page 2 + content,
    # 7 font, 8 info, 9 object stream, 10 xref stream
    page1_new = (b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 300] /Contents 4 0 R "
                 b"/Resources << /Font << /F1 7 0 R >> >> >>")
    upd = incremental_xref_stream(base, {3: page1_new, 20: b"<< /Note (added by update) >>"}, free=[8], size=20,
                                  info=None)
    add("hand-incremental-xrefstream.pdf", upd,
        note="incremental update (xref stream): page 1 replaced, object 8 freed, object 20 added")

    upd2 = incremental_classic(base, {3: page1_new, 20: b"<< /Note (classic update) >>"}, size=21, info=8)
    add("hand-incremental-mixed.pdf", upd2, note="classic-table update appended to an xref-stream file (/Prev chain)")

    upd3 = incremental_xref_stream(upd, {3: page1_new.replace(b"400 300", b"500 500")}, size=21,
                                   info=None)
    add("hand-incremental-twice.pdf", upd3, note="two incremental updates, the newest wins")

    # --- damaged / unusual containers -------------------------------------------------------------------------
    q = open(os.path.join(FIX, "rich-qpdf-objstm.pdf"), "rb").read()
    add("damaged-startxref.pdf", q[:q.rindex(b"startxref")] + b"startxref\n7\n%%EOF\n",
        note="startxref points to a wrong offset", golden_from="rich-qpdf-objstm.pdf")
    add("damaged-truncated.pdf", q[:q.rindex(b"startxref") - 40],
        note="file cut off before startxref (no xref pointer, no EOF marker)", golden_from="rich-qpdf-objstm.pdf")
    add("junk-before-header.pdf", b"JUNK-BYTES-BEFORE-THE-HEADER\r\n" * 4 + q,
        note="garbage before %PDF- (offsets are relative to the header)", golden_from="rich-qpdf-objstm.pdf")
    add("trailing-garbage.pdf", q + b"\x00garbage after EOF\n" * 400,
        note="more than 5500 bytes of garbage after %%EOF", golden_from="rich-qpdf-objstm.pdf")

    # --- encrypted: not supported (yet) - the parser has to refuse it with a clear exception ------------------------
    run("qpdf", "--object-streams=generate", "--encrypt", "", "owner-secret", "256", "--", rich,
        os.path.join(FIX, "encrypted-aes256.pdf"))
    add("encrypted-aes256.pdf", note="AES-256 encrypted (empty user password) - must be refused", importable=False)

    with open(os.path.join(FIX, "manifest.json"), "w") as f:
        json.dump(manifest, f, indent=1)
    shutil.rmtree(TMP, ignore_errors=True)
    print("wrote %d fixtures" % len(manifest))


if __name__ == "__main__":
    main()
