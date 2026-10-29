<?php

namespace Bangsamu\LibraryClay\Contracts;

/**
 * Ganti format dan reset_period nomor per nomor yang dibuat, mis. pola sequence lain untuk project tertentu.
 * App mengaktifkannya dengan bind di container:
 *     $this->app->bind(NumberFormatResolver::class, MyNumberFormatResolver::class);
 * Tanpa binding, RequisitionNumberService memakai format_string dan reset_period tipe apa adanya.
 */
interface NumberFormatResolver
{
    /**
     * @param  string  $format  format_string tipe dari report_number_format
     * @param  string  $resetPeriod  reset_period tipe (none, yearly, monthly)
     * @param  array<string, mixed>  $params  token nomor: type_id, type_code, project, source, department, ...
     * @return array{format: string, reset_period: string}
     */
    public function resolve(int $typeId, string $format, string $resetPeriod, array $params): array;
}
