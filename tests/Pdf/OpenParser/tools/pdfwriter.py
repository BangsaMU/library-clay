"""
A tiny PDF writer used to hand-craft the edge-case fixtures (cross-reference streams with unusual /W and /Index,
all PNG predictors, filter chains, hybrid-reference files, incremental updates, ...).

It is a test helper only and intentionally minimal.
"""
import base64
import random
import zlib

HEADER = b"%PDF-1.5\n%\xe2\xe3\xcf\xd3\n"


def a85(data: bytes) -> bytes:
    return base64.a85encode(data, adobe=False) + b"~>"


def ahx(data: bytes) -> bytes:
    return data.hex().encode() + b">"


def encode_chain(data: bytes, chain):
    """Apply filters in *encoding* order (the first element is applied first).

    Returns (encoded, /Filter value) where the /Filter array lists the decoders in decoding order."""
    names = {"flate": b"/FlateDecode", "a85": b"/ASCII85Decode", "ahx": b"/ASCIIHexDecode"}
    for f in chain:
        if f == "flate":
            data = zlib.compress(data, 6)
        elif f == "a85":
            data = a85(data)
        elif f == "ahx":
            data = ahx(data)
        else:
            raise ValueError(f)
    decoders = [names[f] for f in reversed(chain)]
    if not decoders:
        return data, b""
    if len(decoders) == 1:
        return data, b" /Filter " + decoders[0]
    return data, b" /Filter [" + b" ".join(decoders) + b"]"


def png_encode(rows, filter_types):
    """PNG-predict a list of equally sized rows. filter_types: list of 0..4 (cycled)."""
    out = bytearray()
    prev = bytes(len(rows[0]))
    bpp = 1
    for i, row in enumerate(rows):
        t = filter_types[i % len(filter_types)]
        enc = bytearray(len(row))
        for j, x in enumerate(row):
            a = row[j - bpp] if j >= bpp else 0
            b = prev[j]
            c = prev[j - bpp] if j >= bpp else 0
            if t == 0:
                p = 0
            elif t == 1:
                p = a
            elif t == 2:
                p = b
            elif t == 3:
                p = (a + b) // 2
            else:
                pp = a + b - c
                pa, pb, pc = abs(pp - a), abs(pp - b), abs(pp - c)
                p = a if (pa <= pb and pa <= pc) else (b if pb <= pc else c)
            enc[j] = (x - p) & 0xFF
        out.append(t)
        out += enc
        prev = row
    return bytes(out)


def tiff_encode(rows):
    """TIFF predictor 2 (8 bit, 1 colour)."""
    out = bytearray()
    for row in rows:
        enc = bytearray(row)
        for j in range(len(row) - 1, 0, -1):
            enc[j] = (row[j] - row[j - 1]) & 0xFF
        out += enc
    return bytes(out)


class Builder:
    def __init__(self, header=HEADER, prefix=b""):
        self.buf = bytearray(prefix + header)
        self.offsets = {}

    # -- objects -------------------------------------------------------------------------------------------------
    def obj(self, num, body: bytes, gen=0):
        self.offsets[num] = len(self.buf)
        self.buf += b"%d %d obj\n" % (num, gen) + body + b"\nendobj\n"

    def stream(self, num, extra: bytes, data: bytes, length_ref=None):
        length = b"%d 0 R" % length_ref if length_ref else b"%d" % len(data)
        self.obj(num, b"<<" + extra + b" /Length " + length + b" >>\nstream\n" + data + b"\nendstream")

    # -- cross-reference -----------------------------------------------------------------------------------------
    def classic_xref(self, entries, trailer_extra: bytes, size: int, prev=None, fill_free=True):
        """entries: {objnum: offset}. Writes one subsection per consecutive run. Returns the xref offset."""
        pos = len(self.buf)
        self.buf += b"xref\n"
        nums = sorted(entries)
        if fill_free and 0 not in entries:
            self.buf += b"0 1\n0000000000 65535 f \n"
        runs = []
        for n in nums:
            if runs and runs[-1][-1] == n - 1:
                runs[-1].append(n)
            else:
                runs.append([n])
        for run in runs:
            self.buf += b"%d %d\n" % (run[0], len(run))
            for n in run:
                off = entries[n]
                if off is None:
                    self.buf += b"0000000000 00001 f \n"
                else:
                    self.buf += b"%010d 00000 n \n" % off
        t = b"<< /Size %d" % size + trailer_extra
        if prev is not None:
            t += b" /Prev %d" % prev
        self.buf += b"trailer\n" + t + b" >>\n"
        return pos

    @staticmethod
    def xref_stream_parts(entries, widths, trailer_extra: bytes, size: int, index=None, prev=None,
                          chain=("flate",), predictor=None, filter_types=(2,)):
        """entries: list of (type, f2, f3) in the order of the /Index ranges.

        predictor: None, 'png' or 'tiff'. Returns (dictionary entries, encoded data)."""
        w1, w2, w3 = widths
        rows = []
        for t, f2, f3 in entries:
            if t == 0:  # free entries: clamp to what the field widths can hold (e.g. generation 65535 in 1 byte)
                f2 = min(f2, (1 << (8 * w2)) - 1) if w2 else 0
                f3 = min(f3, (1 << (8 * w3)) - 1) if w3 else 0
            row = b""
            if w1:
                row += t.to_bytes(w1, "big")
            if w2:
                row += f2.to_bytes(w2, "big")
            if w3:
                row += f3.to_bytes(w3, "big")
            rows.append(row)
        parms = b""
        if predictor == "png":
            data = png_encode(rows, list(filter_types))
            parms = b"<< /Predictor 12 /Columns %d >>" % sum(widths)
        elif predictor == "tiff":
            data = tiff_encode(rows)
            parms = b"<< /Predictor 2 /Columns %d >>" % sum(widths)
        else:
            data = b"".join(rows)
        data, filt = encode_chain(data, list(chain))
        extra = b""
        if parms:
            if len(chain) > 1:
                # /DecodeParms is an array parallel to /Filter (decoding order); the predictor belongs to Flate
                items = [parms if f == "flate" else b"null" for f in reversed(chain)]
                extra = b" /DecodeParms [" + b" ".join(items) + b"]"
            else:
                extra = b" /DecodeParms " + parms
        d = b" /Type /XRef /Size %d /W [%d %d %d]" % (size, w1, w2, w3)
        if index is not None:
            d += b" /Index [" + b" ".join(b"%d" % x for x in index) + b"]"
        if prev is not None:
            d += b" /Prev %d" % prev
        d += trailer_extra + filt + extra
        return d, data

    def xref_stream(self, num, entries, widths, trailer_extra: bytes, size: int, index=None, prev=None,
                    chain=("flate",), predictor=None, filter_types=(2,), length_ref=None):
        """Writes a cross-reference stream object and returns its offset."""
        d, data = self.xref_stream_parts(entries, widths, trailer_extra, size, index, prev, chain, predictor,
                                         filter_types)
        pos = len(self.buf)
        self.offsets[num] = pos
        self.stream(num, d, data, length_ref=length_ref)
        return pos

    def finish(self, startxref):
        self.buf += b"startxref\n%d\n%%%%EOF\n" % startxref
        return bytes(self.buf)


# -- shared document content ------------------------------------------------------------------------------------------
def content_stream(label: str) -> bytes:
    lines = [b"BT /F1 24 Tf 72 760 Td (" + label.encode() + b") Tj ET"]
    for i in range(12):
        lines.append(b"BT /F1 11 Tf 72 %d Td (Handmade fixture line %d - the quick brown fox) Tj ET" % (700 - i * 16, i))
    lines.append(b"0.1 0.4 0.8 rg 72 120 200 40 re f")
    return b"\n".join(lines)


def document_objects(pages=2):
    """Returns {objnum: body} for a small document and the list of stream objects {objnum: (extra, data)}.

    Layout: 1 catalog, 2 pages, 3.. pages and content streams, then font and info."""
    bodies = {}
    streams = {}
    page_nums = []
    next_num = 3
    font_num = 3 + pages * 2
    info_num = font_num + 1
    for p in range(pages):
        page_num, content_num = next_num, next_num + 1
        next_num += 2
        page_nums.append(page_num)
        w, h = (595, 842) if p % 2 == 0 else (612, 792)
        bodies[page_num] = (
            b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Contents %d 0 R "
            b"/Resources << /Font << /F1 %d 0 R >> >> >>" % (w, h, content_num, font_num)
        )
        streams[content_num] = (b"", content_stream("Handmade page %d" % (p + 1)))
    bodies[1] = b"<< /Type /Catalog /Pages 2 0 R >>"
    bodies[2] = b"<< /Type /Pages /Kids [" + b" ".join(b"%d 0 R" % n for n in page_nums) + b"] /Count %d >>" % pages
    bodies[font_num] = b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>"
    bodies[info_num] = b"<< /Producer (fpdi-open-parser fixture) /Title (Handmade) >>"
    return bodies, streams, info_num


def objstm_body(objs: dict, chain=("flate",)):
    """Build the (extra dict, data) of an object stream for {objnum: bytes}."""
    header = b""
    body = b""
    for num in sorted(objs):
        header += b"%d %d " % (num, len(body))
        body += objs[num] + b"\n"
    first = len(header)
    data, filt = encode_chain(header + body, list(chain))
    return b" /Type /ObjStm /N %d /First %d" % (len(objs), first) + filt, data


# -- complete handmade documents --------------------------------------------------------------------------------------
def doc_xref_stream(widths=(1, 2, 1), predictor="png", filter_types=(2,), chain=("flate",), objstm_chain=("flate",),
                    pages=2, index_split=False, length_ref=False, objstm=True, prefix=b"", skip_zero=False):
    """A document whose non-stream objects live in one object stream; xref stream with the given options."""
    bodies, streams, info_num = document_objects(pages)
    b = Builder(prefix=prefix)
    objstm_num = info_num + 1
    xref_num = info_num + 2 if objstm else info_num + 1
    length_obj_num = xref_num + 1 if length_ref else None
    size = (length_obj_num or xref_num) + 1

    for num, (extra, data) in sorted(streams.items()):
        b.stream(num, extra, data)

    compressed = {}
    if objstm:
        compressed = dict(bodies)
        extra, data = objstm_body(compressed, objstm_chain)
        b.stream(objstm_num, extra, data)
    else:
        for num, body in sorted(bodies.items()):
            b.obj(num, body)

    length_obj_bytes = b""
    if length_ref:
        # fixed width, so that the position of the xref stream is known before its length is
        length_obj_pos = len(b.buf)
        length_obj_bytes = b"%d 0 obj\n%010d\nendobj\n" % (length_obj_num, 0)
        b.offsets[length_obj_num] = length_obj_pos
    xref_pos = len(b.buf) + len(length_obj_bytes)

    entry_for = {0: (0, 0, 65535)}
    for num in range(1, size):
        if num in compressed:
            entry_for[num] = (2, objstm_num, sorted(compressed).index(num))
        elif num == xref_num:
            entry_for[num] = (1, xref_pos, 0)
        elif num in b.offsets:
            entry_for[num] = (1, b.offsets[num], 0)
        else:
            entry_for[num] = (0, 0, 0)

    first = 1 if skip_zero else 0
    ents = [entry_for[n] for n in range(first, size)]
    index = [1, size - 1] if skip_zero else None
    if index_split and not skip_zero:
        # two subsections, as real writers produce after incremental updates
        k = size // 2
        index = [0, k, k, size - k]
    trailer_extra = b" /Root 1 0 R /Info %d 0 R" % info_num

    d, data = b.xref_stream_parts(ents, widths, trailer_extra, size, index=index, chain=chain,
                                  predictor=predictor, filter_types=filter_types)
    if length_ref:
        b.buf += b"%d 0 obj\n%010d\nendobj\n" % (length_obj_num, len(data))
    assert len(b.buf) == xref_pos
    b.offsets[xref_num] = xref_pos
    b.stream(xref_num, d, data, length_ref=length_obj_num)
    return b.finish(xref_pos)


def doc_hybrid(pages=2, hidden_as_free=False):
    """Hybrid-reference file: classic table + /XRefStm.

    hidden_as_free=False: the table does not list the hidden (compressed) objects at all (readable by every qpdf).
    hidden_as_free=True: the table lists them as free entries (what several PDF producers write)."""
    bodies, streams, info_num = document_objects(pages)
    b = Builder()
    objstm_num = info_num + 1
    xrefstm_num = info_num + 2
    size = xrefstm_num + 1
    # objects 1, 2 and the pages live in the object stream, the font and info are plain
    hidden = {n: bodies[n] for n in bodies if n not in (info_num,) and n != 3 + pages * 2}
    plain = {n: bodies[n] for n in bodies if n not in hidden}
    for num, (extra, data) in sorted(streams.items()):
        b.stream(num, extra, data)
    for num, body in sorted(plain.items()):
        b.obj(num, body)
    extra, data = objstm_body(hidden)
    b.stream(objstm_num, extra, data)

    # the cross-reference stream (describes the hidden objects)
    ents = []
    index = []
    order = sorted(hidden)
    # one subsection per run of consecutive hidden numbers
    runs = []
    for n in order:
        if runs and runs[-1][-1] == n - 1:
            runs[-1].append(n)
        else:
            runs.append([n])
    for run in runs:
        index += [run[0], len(run)]
        for n in run:
            ents.append((2, objstm_num, order.index(n)))
    xrefstm_pos = len(b.buf)
    b.xref_stream(xrefstm_num, ents, (1, 2, 1), b" /Root 1 0 R /Info %d 0 R" % info_num, size, index=index,
                  predictor="png")

    # the classic table: everything in use + hidden objects as free entries
    entries = {0: None}
    for n in range(1, size):
        if n in hidden:
            if hidden_as_free:
                entries[n] = None  # free in the table, real location in the stream
            else:
                continue  # not listed in the table at all, real location in the stream
        elif n in b.offsets and n != xrefstm_num:
            entries[n] = b.offsets[n]
        elif n == xrefstm_num:
            entries[n] = xrefstm_pos
        else:
            entries[n] = None
    pos = b.classic_xref({k: v for k, v in entries.items() if k != 0}, b" /Root 1 0 R /Info %d 0 R /XRefStm %d" % (info_num, xrefstm_pos), size)
    return b.finish(pos)


def doc_png_many_objects(count=400, filter_types=(0, 1, 2, 3, 4), widths=(1, 3, 2)):
    """A document with many (uncompressed) objects so that the xref stream has many rows with mixed PNG filters."""
    bodies, streams, info_num = document_objects(2)
    b = Builder()
    xref_num = info_num + 1 + count
    size = xref_num + 1
    for num, (extra, data) in sorted(streams.items()):
        b.stream(num, extra, data)
    for num, body in sorted(bodies.items()):
        b.obj(num, body)
    filler_start = info_num + 1
    rnd = random.Random(7)
    for i in range(count):
        b.obj(filler_start + i, b"(filler %d %s)" % (i, bytes(rnd.choice(b"abcdefghij") for _ in range(rnd.randint(1, 40)))))
    ents = [(0, 0, 65535)]
    for n in range(1, xref_num):
        ents.append((1, b.offsets[n], 0))
    pos = len(b.buf)
    ents.append((1, pos, 0))
    pos = b.xref_stream(xref_num, ents, widths, b" /Root 1 0 R /Info %d 0 R" % info_num, size, predictor="png",
                        filter_types=filter_types)
    return b.finish(pos)
