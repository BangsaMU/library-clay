# OpenParser (PDF 1.5–1.7 untuk FPDI)

Parser untuk FPDI gratis (`setasign/fpdi` ^2.3, MIT) yang bisa membaca PDF dengan cross-reference stream dan object
stream (umumnya PDF 1.5+, tetapi bisa juga ber-header 1.4), yang ditolak parser bawaan FPDI dengan pesan
"This PDF document probably uses a compression technique which is not supported by the free parser shipped with
FPDI". File tidak dikonversi (tanpa qpdf/Ghostscript), cukup PHP + `ext-zlib`.

Asal: paket `fpdi-open-parser` (MIT, `LICENSE` di folder ini), implementasi mandiri dari spesifikasi PDF
ISO 32000-1 di atas kelas parser FPDI yang MIT. Tidak terkait Setasign; "FPDI" hanya menyebut kompatibilitas.
Namespace asli `FpdiOpenParser\` diganti `Bangsamu\LibraryClay\Pdf\OpenParser\`.

## Fitur

- Cross-reference stream (`/Type /XRef`) dengan `/W` dan `/Index` apa pun, predictor PNG (None/Sub/Up/Average/Paeth)
  dan TIFF 2, rantai filter (Flate, LZW, ASCII85, ASCIIHex).
- Object stream (`/Type /ObjStm`), dibaca saat dibutuhkan dan di-cache (maks. 16 stream).
- File hybrid (`/XRefStm`), incremental update (rantai `/Prev`, campuran tabel/stream), file linearized.
- Perbaikan file rusak (startxref salah, file terpotong, sampah sebelum header/sesudah `%%EOF`, offset salah): xref
  dibangun ulang dengan memindai file. Matikan dengan `CrossReference::$repair = false`.

## Pemakaian

| Ganti                         | Dengan                                              |
|-------------------------------|-----------------------------------------------------|
| `Mpdf\Mpdf`                   | `Bangsamu\LibraryClay\Pdf\OpenParser\Mpdf`          |
| `setasign\Fpdi\Tcpdf\Fpdi`    | `Bangsamu\LibraryClay\Pdf\OpenParser\Tcpdf\Fpdi`    |
| `setasign\Fpdi\Fpdi` (FPDF)   | `Bangsamu\LibraryClay\Pdf\OpenParser\Fpdi`          |
| `setasign\Fpdi\Tfpdf\Fpdi`    | `Bangsamu\LibraryClay\Pdf\OpenParser\Tfpdf\Fpdi`    |

API sama persis dengan FPDI. Kelas sendiri yang memakai `FpdiTrait` cukup menambah
`use \Bangsamu\LibraryClay\Pdf\OpenParser\OpenParserTrait;`.

## Batasan

- PDF terenkripsi tetap ditolak (`CrossReferenceException::ENCRYPTED`), sama seperti parser gratis; dekripsi dulu
  (mis. `qpdf --decrypt`).
- Content stream halaman yang memakai predictor tetap di-decode FPDI sendiri dan ditolak (sangat jarang).
- Seperti FPDI pada umumnya, halaman diimpor sebagai template: form, anotasi, dan tanda tangan tidak ikut.
- Memperbaiki file rusak membaca seluruh file ke memori sekali.

## Test

`composer test` di library-clay. Setiap objek yang dibaca dibandingkan dengan hasil baca qpdf dari file yang sama
(golden dump di `tests/Pdf/OpenParser/fixtures/golden`), semua halaman diimpor dengan FPDF, TCPDF, tFPDF, dan mPDF,
lalu (bila `pdftoppm` ada) dirender dan dibandingkan dengan aslinya. Fixture dibuat ulang dengan
`python3 tests/Pdf/OpenParser/tools/make_fixtures.py` (butuh reportlab, pillow, pikepdf, qpdf, ghostscript).
