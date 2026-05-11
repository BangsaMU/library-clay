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
    public function formatNumber(int $type_id, $squence_batch, array $param = []): array
    {
        $paddedNumber = null;
        $fullYear = date('Y'); // e.g., 2025
        $shortYear = date('y'); // e.g., 25

        $RequisitionType = RequisitionType::find($type_id);
        $type_code = $RequisitionType->code??0;

        // Ambil format string dari database
        $format = ReportNumberFormat::where('type_id', $type_id)->value('format_string');

        // dd($format);

        // Default format jika tidak ada di database
        if (!$format) {
            $format = '{type_code}-{year}-{sequence}';
        }

        // Gabungkan placeholder default + tambahan
        $replacements = array_merge([
            'type_code' => $type_code,
            'year' => $shortYear,
        ], $param);

        // Ganti semua placeholder berdasarkan key-nya
        if (is_null($squence_batch)) {
            $squence_batch = preg_replace_callback(
                '/\{(\w+)\}/',
                function ($matches) use ($replacements) {
                    $key = $matches[1];
                    return $replacements[$key] ?? $matches[0]; // kalau nggak ada, biarkan tetap
                },
                $format,
            );
        }
        // dd($squence_batch);
        log::info("Generated formatNumber type_id: {$type_id} sequence batch: {$squence_batch} param: ".json_encode($param));

        // Ambil data kalkulasi
        $calculation = $this->calculateNextNumber($squence_batch);
        // dd($calculation);
        // array:4 [▼ // app\Services\RequisitionNumberService.php:50
        // "next_sequence" => 2
        // "full_year" => "2025"
        // "short_year" => "25"
        // "squence_batch" => "GDA-25-{sequence}"
        // ]
        $paddedNumber = str_pad($calculation['next_sequence'], 4, '0', STR_PAD_LEFT);

        // update placeholder sequence
        // 1. Definisikan pemetaan tanggal secara dinamis
        $dateReplacements = [
            'dd'   => date('d'),          // 01 - 31
            'mm'   => date('m'),          // 01 - 12
            'MM'   => date('M'),          // Jan - Dec
            'yy'   => date('y'),          // 25
            'YYYY' => date('Y'),          // 2025
            'year' => $shortYear,         // Alias untuk fallback format lama
            'type_code' => $type_code,
            'sequence' => $paddedNumber,
        ];


        // Ambil Custom Replacements dari Database (Tabel dashboard_settings)
        $dbSettings = DB::table('dashboard_settings')
            ->where('group', 'report_number_format')
            ->pluck('value', 'key') // Langsung ambil pair key => value
            ->toArray();

        $dbReplacements = [];
 
        
        


        foreach ($dbSettings as $key => $value) {
            if (function_exists($value)) {
                try {
                    $dbReplacements[$key] = $value(); 
                } catch (\Exception $e) {
                    // Jika fungsi ada tapi gagal eksekusi
                    \Log::error("Gagal mengeksekusi helper [{$value}] untuk placeholder {{$key}}: " . $e->getMessage());
                    // Jangan masukkan ke dbReplacements agar tetap render mentah {key}
                }
            } else {
                // LOG ERROR: Helper tidak ditemukan
                \Log::warning("Helper fungsi [{$value}()] tidak ditemukan untuk placeholder {{$key}} di dashboard_settings.");
                
                // Opsional: Jika value di DB bukan fungsi tapi teks biasa, 
                // Anda bisa memutuskan ingin menampilkannya atau tidak.
                // Jika ingin tetap render mentah {project}, jangan masukkan ke array.
            }
        }


        // 2. Gabungkan dengan parameter tambahan ($param) dari user/sistem
        $replacements = array_merge($dateReplacements, $param ?? []);
        log::info('Replacements for number formatting', $replacements);

        $formatted = preg_replace_callback(
            '/\{(\w+)\}/',
            function ($matches) use ($replacements) {
                $key = $matches[1];
                return $replacements[$key] ?? $matches[0]; // kalau nggak ada, biarkan tetap
            },
            $format,
        );

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
     * This is safe to use for display purposes on a form.
     *
     * @param string $type
     * @return string
     */
    public function predictNext(string $type_id): string
    {
        // $calculation = $this->calculateNextNumber($formatted);
        // dd($calculation);
        // $type_id = 1;
        $squence_batch = null;
        return $this->formatNumber($type_id, $squence_batch, ['project' => 'MEI'])['formatted'];

        // return "{$type}-{$calculation['short_year']}-{$paddedNumber}";
    }

    /**
     * Generates and logs the definitive next number for a given requisition.
     * $requisition = (object) [
     *       'id' => 0,
     *       'user_id' => auth()->user()->id,
     *   ];
     *  // dd($requisition);
     *  // Panggil service menggunakan helper app()
     *  $numberService = app(\Bangsamu\LibraryClay\Services\RequisitionNumberService::class);
     *  $codeNumber = $numberService->generate($requisition,1);
     *  dd(1,$codeNumber);
     *
     * @param Requisition $requisition
     * @param string $type
     * @return string
     */
    public function generate($formRequest, int $type_id): string
    {
        // $type_id = 1;
        // We re-calculate the number here to get the absolute latest value at the moment of saving.
        // $calculation = $this->calculateNextNumber($type);

        $squence_batch = null;
        // $calculation = $this->formatNumber($type_id, $squence_batch, ['project' => 'MEI']); // misal ada kirim param project
        $calculation = $this->formatNumber($type_id, $squence_batch, []);
        // $nextCodeNumber = $this->predictNext($type_id);
        //  $calculation = $this->calculateNextNumber($formatted);
        $nextSequenceNumber = $calculation['next_sequence'];
        $type = $calculation['squence_batch'];
        $year = $calculation['full_year'];

        // Create a new log entry in our helper table.
        SequenceNumbers::updateOrInsert(
            // 1. Cek apakah kombinasi ini sudah ada
            [
                'object_id' => $formRequest->id,
                'type'      => $type,
                'year'      => $year,
            ],
            // 2. Data yang akan di-insert/update jika diperlukan
            [
                'sequence_number' => $nextSequenceNumber,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]
        );


        return $calculation['formatted'];

        // Format the final number string for saving.
        $paddedNumber = str_pad($nextSequenceNumber, 4, '0', STR_PAD_LEFT);

        return "{$type}-{$calculation['short_year']}-{$paddedNumber}";
    }

    /**
     * A private helper to contain the core calculation logic, used by both public methods.
     *
     * @param string $type
     * @return array
     */
    private function calculateNextNumber(string $squence_batch): array
    {
        $fullYear = date('Y'); // e.g., 2025
        $shortYear = date('y'); // e.g., 25

        // Find the highest sequence number for the given type and year
        $maxSequence = SequenceNumbers::where('type', $squence_batch)
            ->where('year', $fullYear) //di DMS ada temuan menyebabkan bug jika masih draft kemudian ketika save update ganti tahun bisa di review lagi
            ->max('sequence_number');
        // dd($maxSequence,($maxSequence ?? 0) + 1);
        return [
            'next_sequence' => ($maxSequence ?? 0) + 1,
            'full_year' => $fullYear,
            'short_year' => $shortYear,
            'squence_batch' => $squence_batch,
        ];
    }
}
