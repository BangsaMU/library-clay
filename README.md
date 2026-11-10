# sso

Integrasikan CLAY dengan fitur Master function helper

## Running number: token yang tidak memisahkan urutan (v2.5.0)

`Contracts\NumberFormatResolver::resolve()` boleh mengembalikan `bucket_literal_tokens`, mis. `['department']`. Token itu
tetap tercetak di nomor tetapi tidak ikut membentuk bucket (kelompok urutan). Dengan format
`{project}-{type_code}-{source}-{department}-{sequence:5}`, bucket menjadi `28000-SPB-BLR-{department}-{sequence:5}`, jadi
semua departemen di project/tipe/lokasi yang sama berbagi satu urutan. Fitur ini hanya untuk mode `reset_period`; token
tanggal tetap diatur `reset_period`.

- Nomor yang barisnya masih di bucket lengkap (`28000-SPB-BLR-CONS-{sequence:5}`, terbit sebelum token dikeluarkan) tetap
  dipegang requisition-nya dan tidak dinomori ulang.
- Bila token di luar bucket berubah (departemen diganti, lokasi sama), urutan tetap dan nomor dibentuk ulang dengan token
  baru. Pengecualian: bila nomor itu sudah dipakai requisition lain, requisition ini mendapat nomor baru.
- `formatNumber()` mengembalikan `full_bucket` di samping `squence_batch`.
- Sebelum mengaktifkan, app wajib menulis penanda cutoff di bucket baru = nomor terbesar dari semua bucket lengkapnya.
  Tanpa itu, urutan baru bisa kembar dengan nomor lama.
- Tanpa `bucket_literal_tokens`, perilaku sama seperti v2.4.x.
- Adopsi nomor tersimpan kini juga menolak urutan yang dipegang penanda cutoff (dulu bentrok dengan unique
  `(type, sequence_number)`).

## Jadwal `telescope:prune`

App yang Telescope-nya aktif otomatis mendapat jadwal `telescope:prune --hours=120` harian (`onOneServer`, simpan 5 hari;
sebelum v2.4.3: 720 jam). Jadwal
didaftarkan saat scheduler menyusun daftar jadwal (`schedule:run`, `schedule:list`), bukan di setiap boot, dan tidak
menulis log (sebelum v2.4.3: log INFO "Telescope prune didaftarkan" di setiap proses artisan).

Atur di `config/libraryclay.php` app:

```php
// jam simpan entri Telescope; 0 = tidak didaftarkan (mis. app sudah punya jadwal prune sendiri)
'telescope_prune_hours' => (int) env('LIBRARYCLAY_TELESCOPE_PRUNE_HOURS', 120),
```
