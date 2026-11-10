<?php

namespace Bangsamu\LibraryClay\Contracts;

/**
 * Ganti format dan reset_period nomor per nomor yang dibuat, mis. pola sequence lain untuk project tertentu.
 * App mengaktifkannya dengan bind di container:
 *     $this->app->bind(NumberFormatResolver::class, MyNumberFormatResolver::class);
 * Tanpa binding, RequisitionNumberService memakai format_string dan reset_period tipe apa adanya.
 *
 * Opsional `bucket_literal_tokens` (sejak v2.5.0): token non-tanggal yang tetap tercetak di nomor tetapi tidak memisahkan
 * urutan, mis. ['department'] = satu urutan untuk semua departemen di project/tipe/lokasi yang sama
 * (bucket `28000-SPB-BLR-{department}-{sequence:5}`). Nomor yang barisnya masih di bucket lengkap (terbit sebelum token
 * dikeluarkan) tetap dipegang SPB-nya.
 */
interface NumberFormatResolver
{
    /**
     * @param  string  $format  format_string tipe dari report_number_format
     * @param  string  $resetPeriod  reset_period tipe (none, yearly, monthly)
     * @param  array<string, mixed>  $params  token nomor: type_id, type_code, project, source, department, ...
     * @return array{format: string, reset_period: string, bucket_literal_tokens?: list<string>}
     */
    public function resolve(int $typeId, string $format, string $resetPeriod, array $params): array;
}
