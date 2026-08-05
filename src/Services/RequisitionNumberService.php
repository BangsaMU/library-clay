<?php

namespace Bangsamu\LibraryClay\Services;

use App\Models\Requisition; 
use Bangsamu\Master\Models\RequisitionType;
use Bangsamu\Master\Models\ReportNumberFormat;
use Bangsamu\Master\Models\SequenceNumbers;
use App\Models\WarehouseFormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RequisitionNumberService
{
    /** Lebar padding {sequence} kalau format tidak menyebut angka, mis. {sequence:3}. */
    private const DEFAULT_SEQUENCE_PAD = 4;

    /**
     * Isi placeholder format. Token yang tidak ada di $replacements dibiarkan utuh —
     * itulah cara bucket penomoran menyimpan {sequence} secara literal.
     */
    private function resolve(string $format, array $replacements): string
    {
        return preg_replace_callback(
            '/\{(\w+)(?::(\d+))?\}/',
            function ($matches) use ($replacements) {
                $key = $matches[1];

                if (!array_key_exists($key, $replacements)) {
                    return $matches[0];
                }

                if ($key === 'sequence') {
                    $width = isset($matches[2]) && $matches[2] !== ''
                        ? (int) $matches[2]
                        : self::DEFAULT_SEQUENCE_PAD;

                    return str_pad((string) $replacements[$key], $width, '0', STR_PAD_LEFT);
                }

                return (string) $replacements[$key];
            },
            $format,
        );
    }

    /** Lebar padding yang diminta format, mis. {sequence:3} => 3. */
    private function sequenceWidth(string $format): int
    {
        if (preg_match('/\{sequence(?::(\d+))?\}/', $format, $matches) && isset($matches[1]) && $matches[1] !== '') {
            return (int) $matches[1];
        }

        return self::DEFAULT_SEQUENCE_PAD;
    }

    public function formatNumber(int $type_id, $squence_batch, array $param = []): array
    {
        $paddedNumber = null;
        $fullYear = date('Y'); // e.g., 2025
        $shortYear = date('y'); // e.g., 25

        $RequisitionType = RequisitionType::find($type_id);
        $type_code = $RequisitionType->code ?? 0;

        // Ambil format string dari database
        $format = ReportNumberFormat::where('type_id', $type_id)->value('format_string');

        // Default format jika tidak ada di database
        if (!$format) {
            $format = '{type_code}-{year}-{sequence}';
        }

        // Gabungkan placeholder default + tambahan
        $replacements = array_merge([
            'type_id' => $type_id,
            'type_code' => $type_code,
            'year' => $shortYear,
        ], $param);

        // Ganti semua placeholder berdasarkan key-nya (kecuali sequence)
        if (is_null($squence_batch)) {
            $squence_batch = $this->resolve($format, $replacements);
        }

        log::info("Generated formatNumber type_id: {$type_id} sequence batch: {$squence_batch} param: " . json_encode($param));

        // Ambil data kalkulasi
        $calculation = $this->calculateNextNumber($squence_batch);

        $paddedNumber = str_pad(
            (string) $calculation['next_sequence'],
            $this->sequenceWidth($format),
            '0',
            STR_PAD_LEFT,
        );

        // update placeholder sequence & date
        $dateReplacements = [
            'dd'   => date('d'),          // 01 - 31
            'mm'   => date('m'),          // 01 - 12
            'MM'   => date('M'),          // Jan - Dec
            'yy'   => date('y'),          // 25
            'YYYY' => date('Y'),          // 2025
            'year' => $shortYear,         // Alias untuk fallback format lama
            'type_code' => $type_code,
            'sequence' => $calculation['next_sequence'],
        ];

        // Ambil Custom Replacements dari Database (Tabel dashboard_settings)
        $dbSettings = DB::table('dashboard_settings')
            ->where('group', 'report_number_format')
            ->pluck('value', 'key')
            ->toArray();

        $dbReplacements = [];

        foreach ($dbSettings as $key => $value) {
            if (function_exists($value)) {
                try {
                    $dbReplacements[$key] = $value($replacements); 
                } catch (\Exception $e) {
                    Log::error("Gagal mengeksekusi helper [{$value}] untuk placeholder {{$key}}: " . $e->getMessage());
                }
            } else {
                Log::warning("Helper fungsi [{$value}()] tidak ditemukan untuk placeholder {{$key}} di dashboard_settings.");
            }
        }

        $replacements = array_merge($dateReplacements, $dbReplacements, $param ?? []);
        Log::info('Replacements for number formatting', $replacements);

        $formatted = $this->resolve($format, $replacements);

        return [
            'type_id' => $type_id,
            'type_code' => $type_code,
            'calculation' => $calculation,
            'squence_batch' => $squence_batch,
            'next_sequence' => $calculation['next_sequence'],
            'full_year' => $fullYear,
            'short_year' => $shortYear,
            'paddedNumber' => $paddedNumber,
            'formatted' => $formatted,
        ];
    }

    /**
     * Predicts the next available number without saving it.
     * Safe to use for display purposes on a form.
     */
    public function predictNext(string $type_id, array $param = []): string
    {
        $squence_batch = null;
        $defaultParams = [
            'project' => 'MEI',
            // 'source' => 'SRC',
            // 'department' => 'DEPT',
        ];
        return $this->formatNumber($type_id, $squence_batch, array_merge($defaultParams, $param))['formatted'];
    }

    /**
     * Generates and logs the definitive next number for a given requisition.
     */
    public function generate($formRequest, int $type_id, array $param = []): string
    {
        $squence_batch = null;
        $defaultParams = [
            'project' => 'MEI',
            // 'source' => 'SRC',
            // 'department' => 'DEPT',
        ];
        $calculation = $this->formatNumber($type_id, $squence_batch, array_merge($defaultParams, $param));

        $nextSequenceNumber = $calculation['next_sequence'];
        $type = $calculation['squence_batch'];
        $year = $calculation['full_year'];

        $attributes = [
            'object_id' => $formRequest->id,
            'type'      => $type,
            'year'      => $year,
        ];

        $values = [
            'sequence_number' => $nextSequenceNumber,
            'created_at'      => now(),
            'updated_at'      => now(),
        ];

        SequenceNumbers::updateOrInsert($attributes, $values);

        Log::info('SequenceNumber generated and saved successfully.', [
            'search_attributes' => $attributes,
            'saved_values'      => $values,
            'formatted_result'  => $calculation['formatted'],
        ]);

        return $calculation['formatted'];
    }

    /**
     * Core calculation logic.
     */
    private function calculateNextNumber(string $squence_batch): array
    {
        $fullYear = date('Y');
        $shortYear = date('y');

        $maxSequence = SequenceNumbers::where('type', $squence_batch)
            ->where('year', $fullYear)
            ->max('sequence_number');

        return [
            'next_sequence' => ($maxSequence ?? 0) + 1,
            'full_year' => $fullYear,
            'short_year' => $shortYear,
            'squence_batch' => $squence_batch,
        ];
    }
}
