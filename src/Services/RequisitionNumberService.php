<?php

namespace Bangsamu\LibraryClay\Services;

use App\Models\Requisition;
use Bangsamu\Master\Models\ReportNumberFormat;
use Bangsamu\Master\Models\RequisitionType;
use Bangsamu\Master\Models\SequenceNumbers;
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

                if (! array_key_exists($key, $replacements)) {
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

    public function formatNumber(int $type_id, $squence_batch, array $param = [], ?int $objectId = null): array
    {
        $paddedNumber = null;
        $fullYear = (int) date('Y'); // e.g., 2025
        $shortYear = date('y'); // e.g., 25

        if (is_null($objectId)) {
            $objectId = isset($param['object_id']) ? (int) $param['object_id'] : (isset($param['id']) ? (int) $param['id'] : null);
        }

        $RequisitionType = RequisitionType::find($type_id);
        $type_code = $RequisitionType->code ?? 0;

        // Ambil format string dari database
        $format = ReportNumberFormat::where('type_id', $type_id)->value('format_string');

        // Default format jika tidak ada di database
        if (! $format) {
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

        log::info("Generated formatNumber type_id: {$type_id} sequence batch: {$squence_batch} param: ".json_encode($param));

        $existingSequence = null;
        if ($objectId > 0) {
            $existingSequence = SequenceNumbers::where('object_id', $objectId)
                ->where('type', $squence_batch)
                ->where('year', $fullYear)
                ->first();
        }

        if ($existingSequence) {
            $nextSeq = (int) $existingSequence->sequence_number;
            $calculation = [
                'next_sequence' => $nextSeq,
                'full_year' => $fullYear,
                'short_year' => $shortYear,
                'squence_batch' => $squence_batch,
            ];
        } else {
            // Ambil data kalkulasi
            $calculation = $this->calculateNextNumber($squence_batch, $fullYear);
            $nextSeq = $calculation['next_sequence'];
        }

        $paddedNumber = str_pad(
            (string) $nextSeq,
            $this->sequenceWidth($format),
            '0',
            STR_PAD_LEFT,
        );

        // update placeholder sequence & date
        $dateReplacements = [
            'dd' => date('d'),          // 01 - 31
            'mm' => date('m'),          // 01 - 12
            'MM' => date('M'),          // Jan - Dec
            'yy' => date('y'),          // 25
            'YYYY' => date('Y'),          // 2025
            'year' => $shortYear,         // Alias untuk fallback format lama
            'type_code' => $type_code,
            'sequence' => $nextSeq,
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
                    Log::error("Gagal mengeksekusi helper [{$value}] untuk placeholder {{$key}}: ".$e->getMessage());
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
            'next_sequence' => $nextSeq,
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
    public function predictNext(string $type_id, array $param = [], ?int $objectId = null): string
    {
        $squence_batch = null;
        $defaultParams = [
            'project' => 'MEI',
        ];
        if (is_null($objectId)) {
            $objectId = isset($param['object_id']) ? (int) $param['object_id'] : (isset($param['id']) ? (int) $param['id'] : null);
        }

        return $this->formatNumber((int) $type_id, $squence_batch, array_merge($defaultParams, $param), $objectId)['formatted'];
    }

    /**
     * Generates and logs the definitive next number for a given requisition.
     */
    public function generate($formRequest, int $type_id, array $param = []): string
    {
        Log::info('generate SequenceNumber:', [
            'formRequest' => $formRequest,
            'type_id' => $type_id,
            'param' => $param,
        ]);

        $defaultParams = [
            'project' => 'MEI',
        ];
        $mergedParams = array_merge($defaultParams, $param);

        $objectId = (int) ($formRequest->id ?? 0);
        $fullYear = (int) date('Y');

        $RequisitionType = RequisitionType::find($type_id);
        $type_code = $RequisitionType->code ?? 0;
        $format = ReportNumberFormat::where('type_id', $type_id)->value('format_string') ?? '{type_code}-{year}-{sequence}';

        $batchFormat = $this->resolve($format, array_merge([
            'type_id' => $type_id,
            'type_code' => $type_code,
            'year' => date('y'),
        ], $mergedParams));

        $existingSequence = null;
        if ($objectId > 0) {
            $existingSequence = SequenceNumbers::where('object_id', $objectId)
                ->where('type', $batchFormat)
                ->where('year', $fullYear)
                ->first();
        }

        if ($existingSequence) {
            $nextSequenceNumber = (int) $existingSequence->sequence_number;
        } else {
            $nextSequenceNumber = $this->getNextSequenceNumber($batchFormat, $fullYear);

            if ($objectId > 0) {
                SequenceNumbers::updateOrInsert(
                    ['object_id' => $objectId],
                    [
                        'type' => $batchFormat,
                        'year' => $fullYear,
                        'sequence_number' => $nextSequenceNumber,
                        'updated_at' => now(),
                        'created_at' => $existingSequence->created_at ?? now(),
                    ]
                );
            }
        }

        $paddedNumber = str_pad(
            (string) $nextSequenceNumber,
            $this->sequenceWidth($format),
            '0',
            STR_PAD_LEFT
        );

        $dateReplacements = [
            'dd' => date('d'),
            'mm' => date('m'),
            'MM' => date('M'),
            'yy' => date('y'),
            'YYYY' => date('Y'),
            'year' => date('y'),
            'type_code' => $type_code,
            'sequence' => $nextSequenceNumber,
        ];

        $dbSettings = DB::table('dashboard_settings')
            ->where('group', 'report_number_format')
            ->pluck('value', 'key')
            ->toArray();

        $dbReplacements = [];
        foreach ($dbSettings as $key => $value) {
            if (function_exists($value)) {
                try {
                    $dbReplacements[$key] = $value($mergedParams);
                } catch (\Exception $e) {
                    Log::error("Gagal helper [{$value}]: ".$e->getMessage());
                }
            }
        }

        $replacements = array_merge($dateReplacements, $dbReplacements, $mergedParams);
        $formatted = $this->resolve($format, $replacements);

        Log::info('generate SequenceNumber: generated and saved successfully.', [
            'object_id' => $objectId,
            'batch' => $batchFormat,
            'sequence_number' => $nextSequenceNumber,
            'formatted_result' => $formatted,
        ]);

        return $formatted;
    }

    /**
     * Core calculation logic.
     */
    public function calculateNextNumber(string $squence_batch, ?int $fullYear = null): array
    {
        $fullYear = $fullYear ?? (int) date('Y');
        $shortYear = date('y', strtotime("{$fullYear}-01-01"));

        $nextSequence = $this->getNextSequenceNumber($squence_batch, $fullYear);

        return [
            'next_sequence' => $nextSequence,
            'full_year' => $fullYear,
            'short_year' => $shortYear,
            'squence_batch' => $squence_batch,
        ];
    }

    /**
     * Get the smallest available sequence number (fills gaps if any).
     */
    public function getNextSequenceNumber(string $squence_batch, int $year): int
    {
        $existsOne = SequenceNumbers::where('type', $squence_batch)
            ->where('year', $year)
            ->where('sequence_number', 1)
            ->exists();

        if (! $existsOne) {
            return 1;
        }

        $nextSequence = SequenceNumbers::from('sequence_numbers as t1')
            ->where('t1.type', $squence_batch)
            ->where('t1.year', $year)
            ->whereNotExists(function ($query) use ($squence_batch, $year) {
                $query->select(DB::raw(1))
                    ->from('sequence_numbers as t2')
                    ->where('t2.type', $squence_batch)
                    ->where('t2.year', $year)
                    ->whereColumn('t2.sequence_number', '=', DB::raw('t1.sequence_number + 1'));
            })
            ->min(DB::raw('t1.sequence_number + 1'));

        return (int) ($nextSequence ?? 1);
    }
}
