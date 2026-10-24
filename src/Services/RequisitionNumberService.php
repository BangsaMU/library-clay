<?php

namespace Bangsamu\LibraryClay\Services;

use App\Models\Requisition;
use Bangsamu\Master\Models\ReportNumberFormat;
use Bangsamu\Master\Models\RequisitionType;
use Bangsamu\Master\Models\SequenceNumbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RequisitionNumberService
{
    /** Lebar padding {sequence} kalau format tidak menyebut angka, mis. {sequence:3}. */
    private const DEFAULT_SEQUENCE_PAD = 4;

    /** Token tanggal yang bisa muncul di format nomor. */
    private const DATE_TOKENS = ['dd', 'mm', 'MM', 'yy', 'YYYY', 'year'];

    /**
     * Token tanggal yang ikut membentuk bucket per report_number_format.reset_period.
     * Token tanggal lain tetap literal di bucket, jadi urutan tidak reset walau tanggalnya tercetak.
     */
    private const RESET_TOKENS = [
        'none' => [],
        'yearly' => ['yy', 'YYYY', 'year'],
        'monthly' => ['yy', 'YYYY', 'year', 'mm', 'MM'],
    ];

    /** Token yang wajib ada di format agar reset tidak menghasilkan nomor kembar (code_number unik). */
    public const RESET_REQUIRED_TOKENS = [
        'yearly' => [['yy', 'YYYY', 'year']],
        'monthly' => [['yy', 'YYYY', 'year'], ['mm', 'MM']],
    ];

    private static ?bool $hasResetPeriodColumn = null;

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

        if ($this->usesResetPeriod()) {
            return $this->predictWithResetPeriod($type_id, $param, (int) $objectId);
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

        if (! $existingSequence) {
            [$nextSeq, $formatted] = $this->skipTakenNumbers(
                $squence_batch,
                $fullYear,
                $nextSeq,
                (int) $objectId,
                fn (int $sequence) => $this->resolve($format, array_merge($replacements, ['sequence' => $sequence])),
            );
            $calculation['next_sequence'] = $nextSeq;
            $paddedNumber = str_pad((string) $nextSeq, $this->sequenceWidth($format), '0', STR_PAD_LEFT);
        }

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
     * Safe to use for display purposes on a form. Nomor yang ternyata sudah dipakai
     * requisition lain dicatat ulang ke sequence_numbers lalu dilewati.
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
     *
     * $currentCode = code_number yang sudah tersimpan di DB untuk object ini (bukan input form). Dipakai mode
     * reset_period: nomor dipertahankan selama bucket sama, dan diadopsi bila baris sequence-nya belum ada.
     */
    public function generate($formRequest, int $type_id, array $param = [], ?string $currentCode = null): string
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

        if ($this->usesResetPeriod()) {
            return $this->generateWithResetPeriod($objectId, $type_id, $mergedParams, $currentCode);
        }

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
        }

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

        if (! $existingSequence) {
            [$nextSequenceNumber, $formatted] = $this->skipTakenNumbers(
                $batchFormat,
                $fullYear,
                $nextSequenceNumber,
                $objectId,
                fn (int $sequence) => $this->resolve($format, array_merge($replacements, ['sequence' => $sequence])),
            );

            if ($objectId > 0) {
                SequenceNumbers::updateOrInsert(
                    ['object_id' => $objectId],
                    [
                        'type' => $batchFormat,
                        'year' => $fullYear,
                        'sequence_number' => $nextSequenceNumber,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        Log::info('generate SequenceNumber: generated and saved successfully.', [
            'object_id' => $objectId,
            'batch' => $batchFormat,
            'sequence_number' => $nextSequenceNumber,
            'formatted_result' => $formatted,
        ]);

        return $formatted;
    }

    /**
     * Lewati nomor yang sudah dipakai requisition lain (termasuk yang soft delete, sama seperti
     * validasi unique:requisition,code_number). Terjadi kalau sequence_numbers hilang/kosong:
     * nomor terpakai itu dicatat ulang ke sequence_numbers supaya pencarian celah berikutnya melompatinya.
     *
     * @param  callable(int): string  $formatSequence
     * @return array{0: int, 1: string} [sequence, nomor terformat]
     */
    private function skipTakenNumbers(string $batch, int $year, int $sequence, int $objectId, callable $formatSequence): array
    {
        $formatted = $formatSequence($sequence);

        for ($guard = 0; $guard < 100000; $guard++) {
            $owner = DB::table('requisition')
                ->where('code_number', $formatted)
                ->when($objectId > 0, fn ($query) => $query->where('id', '!=', $objectId))
                ->value('id');

            if (! $owner) {
                break;
            }

            SequenceNumbers::insertOrIgnore([
                'object_id' => $owner,
                'type' => $batch,
                'year' => $year,
                'sequence_number' => $sequence,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::warning("Nomor {$formatted} sudah dipakai requisition #{$owner} tapi tidak tercatat di sequence_numbers; dicatat ulang dan dilewati.");

            $next = $this->getNextSequenceNumber($batch, $year);
            $sequence = $next > $sequence ? $next : $sequence + 1;
            $formatted = $formatSequence($sequence);
        }

        return [$sequence, $formatted];
    }

    /**
     * Mode baru aktif bila kolom report_number_format.reset_period sudah ada (app sudah migrate).
     * App lain yang belum punya kolom ini tetap memakai perilaku lama (filter year + isi celah).
     */
    public function usesResetPeriod(): bool
    {
        return self::$hasResetPeriodColumn ??= Schema::hasColumn((new ReportNumberFormat)->getTable(), 'reset_period');
    }

    /** Reset cache deteksi kolom (dipakai test/command setelah migrate). */
    public static function flushResetPeriodCache(): void
    {
        self::$hasResetPeriodColumn = null;
    }

    /**
     * Nomor baru = sequence terbesar di bucket + 1, kecuali config libraryclay.sequence_fill_gaps = true
     * (default, perilaku lama: celah terkecil yang kosong dipakai lagi).
     */
    private function fillGaps(): bool
    {
        return (bool) config('libraryclay.sequence_fill_gaps', true);
    }

    /**
     * Format, kode tipe, reset_period, semua token (kecuali sequence), dan bucket untuk satu tipe.
     *
     * @return array{format: string, type_code: mixed, reset_period: string, tokens: array<string, mixed>, bucket: string}
     */
    private function numberContext(int $typeId, array $param): array
    {
        $typeCode = RequisitionType::find($typeId)->code ?? 0;
        $formatRow = ReportNumberFormat::where('type_id', $typeId)->first();
        $format = $formatRow->format_string ?? '{type_code}-{year}-{sequence}';
        $resetPeriod = $formatRow->reset_period ?? 'none';
        if (! isset(self::RESET_TOKENS[$resetPeriod])) {
            $resetPeriod = 'none';
        }

        $now = now();
        $base = array_merge(['type_id' => $typeId, 'type_code' => $typeCode], $param);
        $tokens = array_merge([
            'dd' => $now->format('d'),
            'mm' => $now->format('m'),
            'MM' => $now->format('M'),
            'yy' => $now->format('y'),
            'YYYY' => $now->format('Y'),
            'year' => $now->format('y'),
            'type_id' => $typeId,
            'type_code' => $typeCode,
        ], $this->helperReplacements($base), $param);
        unset($tokens['sequence']);

        $literalDateTokens = array_diff(self::DATE_TOKENS, self::RESET_TOKENS[$resetPeriod]);
        $bucket = $this->resolve($format, array_diff_key($tokens, array_flip($literalDateTokens)));

        return [
            'format' => $format,
            'type_code' => $typeCode,
            'reset_period' => $resetPeriod,
            'tokens' => $tokens,
            'bucket' => $bucket,
        ];
    }

    /** Placeholder tambahan dari dashboard_settings group report_number_format (nilai = nama fungsi helper). */
    private function helperReplacements(array $replacements): array
    {
        $result = [];
        $settings = DB::table('dashboard_settings')
            ->where('group', 'report_number_format')
            ->pluck('value', 'key')
            ->toArray();

        foreach ($settings as $key => $function) {
            if (! function_exists($function)) {
                continue;
            }
            try {
                $result[$key] = $function($replacements);
            } catch (\Throwable $e) {
                Log::error("Gagal mengeksekusi helper [{$function}] untuk placeholder {{$key}}: ".$e->getMessage());
            }
        }

        return $result;
    }

    private function formatSequence(array $context, int $sequence): string
    {
        return $this->resolve($context['format'], array_merge($context['tokens'], ['sequence' => $sequence]));
    }

    /** Angka sequence dari nomor yang cocok dengan format + token saat ini, mis. ...-00125 => 125. */
    private function sequenceFromCode(array $context, string $code): ?int
    {
        $template = $this->resolve($context['format'], $context['tokens']);
        $pattern = preg_replace('/\\\\\{sequence(?:\\\\:\d+)?\\\\\}/', '(\d+)', preg_quote($template, '/'));

        if ($pattern === null || ! preg_match('/^'.$pattern.'$/', $code, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function nextSequenceInBucket(string $bucket): int
    {
        if (! $this->fillGaps()) {
            return (int) SequenceNumbers::where('type', $bucket)->max('sequence_number') + 1;
        }

        if (! SequenceNumbers::where('type', $bucket)->where('sequence_number', 1)->exists()) {
            return 1;
        }

        $next = SequenceNumbers::from('sequence_numbers as t1')
            ->where('t1.type', $bucket)
            ->whereNotExists(function ($query) use ($bucket) {
                $query->select(DB::raw(1))
                    ->from('sequence_numbers as t2')
                    ->where('t2.type', $bucket)
                    ->whereColumn('t2.sequence_number', '=', DB::raw('t1.sequence_number + 1'));
            })
            ->min(DB::raw('t1.sequence_number + 1'));

        return (int) ($next ?? 1);
    }

    private function codeOwner(string $code, int $objectId): ?int
    {
        $owner = DB::table('requisition')
            ->where('code_number', $code)
            ->when($objectId > 0, fn ($query) => $query->where('id', '!=', $objectId))
            ->value('id');

        return $owner ? (int) $owner : null;
    }

    /**
     * Majukan sequence selama nomornya sudah dipakai requisition lain (data lama tanpa baris sequence).
     * $record = true (hanya saat generate) mencatat ulang pemilik nomor itu ke sequence_numbers.
     *
     * @return array{0: int, 1: string}
     */
    private function skipTakenInBucket(array $context, int $sequence, int $objectId, bool $record): array
    {
        $formatted = $this->formatSequence($context, $sequence);

        for ($guard = 0; $guard < 100000; $guard++) {
            $owner = $this->codeOwner($formatted, $objectId);
            if (! $owner) {
                break;
            }

            if ($record) {
                SequenceNumbers::insertOrIgnore([
                    'object_id' => $owner,
                    'type' => $context['bucket'],
                    'year' => (int) now()->format('Y'),
                    'sequence_number' => $sequence,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Log::warning("Nomor {$formatted} sudah dipakai requisition #{$owner} tapi tidak tercatat di bucket {$context['bucket']}; dilewati.");
            }

            $next = $this->nextSequenceInBucket($context['bucket']);
            $sequence = $next > $sequence ? $next : $sequence + 1;
            $formatted = $this->formatSequence($context, $sequence);
        }

        return [$sequence, $formatted];
    }

    /** Preview nomor tanpa menulis apa pun. Object yang sudah punya nomor di bucket yang sama tetap nomornya. */
    private function predictWithResetPeriod(int $typeId, array $param, int $objectId): array
    {
        $context = $this->numberContext($typeId, $param);
        $year = (int) now()->format('Y');

        if ($objectId > 0) {
            $row = SequenceNumbers::where('object_id', $objectId)->first();
            if ($row && $row->type === $context['bucket']) {
                $stored = DB::table('requisition')->where('id', $objectId)->value('code_number');
                $formatted = $stored && $this->sequenceFromCode($context, $stored) !== null
                    ? $stored
                    : $this->formatSequence($context, (int) $row->sequence_number);

                return $this->numberResult($typeId, $context, (int) $row->sequence_number, $formatted, $year);
            }
        }

        [$sequence, $formatted] = $this->skipTakenInBucket($context, $this->nextSequenceInBucket($context['bucket']), $objectId, false);

        return $this->numberResult($typeId, $context, $sequence, $formatted, $year);
    }

    /**
     * Nomor final untuk object. Dijalankan dalam transaksi dengan lock baris report_number_format tipe ini,
     * sehingga dua simpan bersamaan antre dan tidak mendapat sequence yang sama. Pemanggil sebaiknya membungkus
     * pembuatan requisition + generate dalam transaksi yang sama agar lock bertahan sampai commit.
     */
    private function generateWithResetPeriod(int $objectId, int $typeId, array $param, ?string $currentCode): string
    {
        return DB::transaction(function () use ($objectId, $typeId, $param, $currentCode) {
            ReportNumberFormat::where('type_id', $typeId)->lockForUpdate()->first();

            $context = $this->numberContext($typeId, $param);
            $bucket = $context['bucket'];
            $year = (int) now()->format('Y');

            if ($objectId > 0) {
                $row = SequenceNumbers::where('object_id', $objectId)->first();

                /* bucket tidak berubah: nomor yang sudah tersimpan dipertahankan apa adanya */
                if ($row && $row->type === $bucket) {
                    return $currentCode ?: $this->formatSequence($context, (int) $row->sequence_number);
                }

                /* nomor tersimpan cocok dengan bucket ini tapi barisnya belum ada/di bucket lain: adopsi nomornya */
                $adopted = $currentCode ? $this->sequenceFromCode($context, $currentCode) : null;
                if ($adopted !== null
                    && ! SequenceNumbers::where('type', $bucket)->where('sequence_number', $adopted)->where('object_id', '!=', $objectId)->exists()
                    && ! $this->codeOwner($currentCode, $objectId)) {
                    $this->recordSequence($objectId, $bucket, $year, $adopted);

                    return $currentCode;
                }
            }

            [$sequence, $formatted] = $this->skipTakenInBucket($context, $this->nextSequenceInBucket($bucket), $objectId, true);

            if ($objectId > 0) {
                $this->recordSequence($objectId, $bucket, $year, $sequence);
            }

            Log::info('generate SequenceNumber (reset_period): saved.', [
                'object_id' => $objectId,
                'batch' => $bucket,
                'reset_period' => $context['reset_period'],
                'sequence_number' => $sequence,
                'formatted_result' => $formatted,
            ]);

            return $formatted;
        });
    }

    private function recordSequence(int $objectId, string $bucket, int $year, int $sequence): void
    {
        SequenceNumbers::updateOrInsert(
            ['object_id' => $objectId],
            [
                'type' => $bucket,
                'year' => $year,
                'sequence_number' => $sequence,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function numberResult(int $typeId, array $context, int $sequence, string $formatted, int $year): array
    {
        return [
            'type_id' => $typeId,
            'type_code' => $context['type_code'],
            'calculation' => [
                'next_sequence' => $sequence,
                'full_year' => $year,
                'short_year' => substr((string) $year, -2),
                'squence_batch' => $context['bucket'],
            ],
            'squence_batch' => $context['bucket'],
            'next_sequence' => $sequence,
            'full_year' => $year,
            'short_year' => substr((string) $year, -2),
            'paddedNumber' => str_pad((string) $sequence, $this->sequenceWidth($context['format']), '0', STR_PAD_LEFT),
            'formatted' => $formatted,
        ];
    }

    /**
     * Pesan error bila format tidak memuat token yang wajib untuk reset_period-nya, null bila valid.
     */
    public static function resetPeriodFormatError(string $format, string $resetPeriod): ?string
    {
        if (! str_contains($format, '{sequence')) {
            return 'Format wajib memuat {sequence}.';
        }

        foreach (self::RESET_REQUIRED_TOKENS[$resetPeriod] ?? [] as $group) {
            $found = collect($group)->contains(fn ($token) => str_contains($format, '{'.$token.'}'));
            if (! $found) {
                return "Reset {$resetPeriod} wajib memuat salah satu token: {".implode('}, {', $group).'}.';
            }
        }

        return null;
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
