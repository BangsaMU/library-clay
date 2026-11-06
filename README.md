# sso

Integrasikan CLAY dengan fitur Master function helper

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
