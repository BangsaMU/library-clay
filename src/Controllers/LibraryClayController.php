<?php

namespace Bangsamu\LibraryClay\Controllers;

use App\Http\Controllers\Controller;
// use DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Http\Controllers\SessionController;
use App\Models\Email;
use App\Models\Changes;
use App\Models\Actions;
use App\Models\AssetGroup;
use App\Models\ActionAttachment;
use App\Models\FileManager;
use App\Models\Project;
use App\Models\Employee;
use Bangsamu\Master\Models\MasterKecamatan;
use App\Models\Gallery;
use App\Models\NotifConfig;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use setasign\Fpdi\Tcpdf\Fpdi;
use Bangsamu\Master\Models\Setting;

Carbon::setLocale('id');

class LibraryClayController extends Controller
{
    /**
     * Mengganti karakter berbahaya di nama file
     */
    public static function sanitizeFileName(string $fileName): string
    {
        // Hilangkan karakter yang tidak aman
        return preg_replace('/[^A-Za-z0-9_\-\. ]/', '', $fileName);
    }

    // Contoh penggunaan
    // $ktp = "3602142804890003";
    // $result = extractDataKTP($ktp);
    // print_r($result);
    static public function extractDataKTP($ktp)
    {
        if(strlen($ktp) !== 16) {
        return [
                'tanggalLahir' => null,
                'jenisKelamin' => null
            ];
        }

        // Ambil 6 digit mulai dari ke-7 hingga ke-12
        $birthDate = substr($ktp, 6, 6);

        // Ambil 2 digit pertama sebagai hari lahir
        $day = intval(substr($birthDate, 0, 2));

        // Tentukan jenis kelamin berdasarkan hari lahir
        $gender = $day >= 40 ? "Perempuan" : "Laki-laki";

        // Jika hari >= 40, kurangi 40 untuk mendapatkan hari yang sebenarnya
        if ($day >= 40) {
            $day -= 40;
        }

        // Ambil bulan dan tahun
        $month = substr($birthDate, 2, 2);
        $year = substr($birthDate, 4, 2);

        // Tentukan abad untuk tahun, misal: 2000-an atau 1900-an
        $fullYear = intval($year) >= 0 && intval($year) <= 23 ? "20$year" : "19$year";

        // Format tanggal ke YYYY-MM-DD
        $formattedDate = sprintf('%s-%s-%02d', $fullYear, $month, $day);

        return [
            'tanggalLahir' => $formattedDate,
            'jenisKelamin' => $gender
        ];
    }

    static public function convertDate($dateString, $outputFormat = 'Y-m-d')
    {
        $formats = [
            'd/m/y',
            'd/m/Y',
            'd/M/Y',
            'd-m-y',
            'd-m-Y',
            'd-M-y',
            'd-M-Y',
            // Add more formats if needed
        ];

        foreach ($formats as $format) {
            try {
                $carbonDate = Carbon::createFromFormat($format, $dateString);
                return $carbonDate->format($outputFormat);
            } catch (\Exception $e) {
                // Continue to the next format if the current one fails
            }
        }

        // If none of the formats is recognized
        return "Error parsing date: Unrecognized date format";
    }

    static public function isValidNIK($nik)
    {
        // Cek panjang dan hanya angka
        if (!preg_match('/^\d{16}$/', $nik)) {
            return false;
        }

        // Ambil 6 digit pertama sebagai kode kecamatan
        $kodeWilayah = substr($nik, 0, 6);

        // Cek apakah kode wilayah ini ada di tabel master_kecamatan
        $wilayah = MasterKecamatan::find($kodeWilayah);
        if (!$wilayah) {
            return false;
        }

        // Ambil dan olah tanggal lahir dari NIK
        $day = intval(substr($nik, 6, 2));
        $month = intval(substr($nik, 8, 2));
        $year = intval(substr($nik, 10, 2));

        // Koreksi untuk perempuan (40+)
        if ($day > 40) {
            $day -= 40;
        }

        // Validasi kombinasi tanggal
        $valid1900 = checkdate($month, $day, 1900 + $year);
        $valid2000 = checkdate($month, $day, 2000 + $year);
        // dd($nik,$wilayah,$valid1900 , $valid2000, $year,$month, $day);
        return $valid1900 || $valid2000;
    }


    static public function updateMaster(array $parmData): string
    {
        // dd($parmData);
        $connection = DB::connection('db_master');

        if (!Schema::connection('db_master')->hasTable($parmData['sync_tabel'])) {
            return "Tabel " . $parmData['sync_tabel'] . " tidak ditemukan di database db_master.";
        }

        // Pisahkan primary key dan data yang di-update
        $conditions = ['id' => $parmData['sync_id']];
        $updateData = $parmData['sync_row'];
        $key_unik = $parmData['key_unik']??'id';
        $key_unik_val = $parmData['sync_id'];
        // $field_key = $parmData['fieldKey'] ?? null;
        $field_key = $parmData['fieldKey'] ?? null; // ✅ Benar


        // Validasi kolom key_unik
        if (!Schema::connection('db_master')->hasColumn($parmData['sync_tabel'], $key_unik)) {
            return "Kolom '$key_unik' tidak ditemukan di tabel '{$parmData['sync_tabel']}' pada database db_master.";
        }

        // Hapus `id` agar tidak menyebabkan masalah saat insert
        unset($updateData['id']);

        // Pastikan ada timestamp untuk insert/update
        $now = Carbon::now();


        // Cek apakah data sudah ada
        // Bangun query dasar
        $query = $connection->table($parmData['sync_tabel'])
            ->where($key_unik,'=', $key_unik_val);

        // Tambahkan kondisi field_key jika diset dan tidak kosong/null khusu tabel master_user_details atau user_details
        if (!empty($field_key)&&($parmData['sync_tabel']=='master_user_details'|| $parmData['sync_tabel']=='user_details')) {
            $query->where('field_key', $field_key);
        }
        // if($parmData['sync_tabel']=='master_user_details'){
        //     dd($parmData,$query->exists(),$query->getBindings());
        // }
        // Cek apakah data sudah ada
        $exists = $query->exists();

        // if ($parmData['sync_tabel']=='master_user_details'|| $parmData['sync_tabel']=='user_details') {
        //     $exists = $connection->table($parmData['sync_tabel'])->where($key_unik,'=', $key_unik_val)->where('field_key','=', $field_key)->exists();
        // }else{
        //     $exists = $connection->table($parmData['sync_tabel'])->where('id', $parmData['sync_id'])->exists();
        // }
        // $exists = true;

        if ($exists) {

            // Jika data ada, update `updated_at`
            $updateData['updated_at'] = $now;

            // Update data
            // dd(9,$parmData,$key_unik,$exists,$parmData['sync_tabel']);
            // $updated = $connection->table($parmData['sync_tabel'])
            //     ->where($key_unik, $key_unik_val)
            //     ->update($updateData);
            $matchCondition = [$key_unik => $key_unik_val];
            // Tambahkan field_key ke kondisi jika ada
            if (!is_null($field_key)) {
                $matchCondition['field_key'] = $field_key;
            }


            // Ambil list kolom dari tabel
            $columns = Schema::connection('db_master')->getColumnListing($parmData['sync_tabel']);

            // Validasi key_unik
            if (!in_array($key_unik, $columns)) {
                return response()->json([
                    'error' => "Kolom `$key_unik` tidak ditemukan di tabel {$parmData['sync_tabel']}"
                ], 400);
            }

            // Validasi field_key jika dipakai sebagai kondisi
            if (!is_null($field_key) && !in_array('field_key', $columns)) {
                return response()->json([
                    'error' => "Kolom `field_key` tidak ditemukan di tabel {$parmData['sync_tabel']}"
                ], 400);
            }

            $matchCondition = [$key_unik => $key_unik_val];
            if (!is_null($field_key)) {
                $matchCondition['field_key'] = $field_key;
            }

            $updateData['updated_at'] = Carbon::now();

            $updated = $connection->table($parmData['sync_tabel'])->updateOrInsert(
                $matchCondition,
                $updateData
            );


            // dd('updated::',$updated,config('MasterCrudConfig.MASTER_DIRECT_EDIT'));
            return $updated ? "Data berhasil diperbarui." : "Gagal memperbarui data.";
        } else {
            // Jika data tidak ada, tambahkan `created_at` dan `updated_at`
            $updateData['created_at'] = $now;
            $updateData['updated_at'] = $now;

            // Insert data baru
            $inserted = $connection->table($parmData['sync_tabel'])->insert(array_merge($conditions, $updateData));

            // dd('inserted::',$inserted,config('MasterCrudConfig.MASTER_DIRECT_EDIT'));
            return $inserted ? "Data berhasil ditambahkan." : "Gagal menambahkan data.";
        }
    }

    static public function callbackSyncMaster(array $parmData): array
    {
        // dd(config('MasterCrudConfig.MASTER_DIRECT_EDIT'),$parmData);
        extract($parmData);
        $list_callback = $sync_list_callback;
        $data["tabel"] = $sync_tabel; //"project";
        $data["rows"][] = $sync_row;
        $id = $sync_id;

        $sync = Http::pool(function (Pool $pool) use ($list_callback, $id, $data, $parmData) {

            extract($parmData);
            $index = 1;
            foreach ($list_callback as $keyL => $url) {
                // dd($keyL , $url);
                if (json_decode($url)) {
                    $list_url = json_decode($url);
                } else {
                    $list_url[] = $url;
                };
                if ($keyL == 'GET') {
                    foreach ($list_url as $url) {
                        $arrayPools[] = $pool->as($keyL . $index)->timeout(config('SsoConfig.curl.TIMEOUT', 5))->withOptions([
                            'verify' => config('SsoConfig.curl.VERIFY', false),
                        ])->get($url . '/' . $id);
                        $index++;
                    }
                }
                if ($keyL == 'POST') {
                    foreach ($list_url as $url) {
                        $arrayPools[] = $pool->as($keyL . $index)->timeout(config('SsoConfig.curl.TIMEOUT', 5))->withOptions([
                            'verify' => config('SsoConfig.curl.VERIFY', false),
                        ])->withBody(json_encode($data), 'application/json')->post($url . '/' . $sync_tabel . '/' . $sync_id);
                        $index++;
                    }
                }
                return $arrayPools;
            }
        });
        // dd($list_callback,json_encode($data),$sync['POST']->body());
        return $sync;
    }

    /*convert array master index ke key dengan id */
    static public function getDataSync(array $dataVar,$withTrashed=false): array
    {
        extract($dataVar);
        $data_sync_master_count = isset($data_master) ? count($data_master) : 0;
        $data_sync_master = [];

        $model_lokal = 'Bangsamu\Master\Models\\' . $tabel_lokal;
        $model_master = isset($data_master) ? null : 'Bangsamu\Master\Models\\' . $tabel_master;


        if ($id) {
            $data_sync_lokal = $model_lokal::where('id', $id);
            if ($model_master) {
                $data_sync_master = $model_master::where('id', $id);
                $data_sync_master_count = $data_sync_master->count();
            }
            // dd($model_master, $data_master, $data_sync_master_count);
            if (empty($data_sync_lokal->count()) or empty($data_sync_master_count)) {
                // Response::make(validateError('data not foud'))->send();
                // exit();
                $data_sync_lokal = $data_sync_lokal->get();
                if ($model_master) {
                    $data_sync_master = $data_sync_master->get();
                }
            } else {
                $data_sync_lokal = $data_sync_lokal->get();
                if ($model_master) {
                    $data_sync_master = $data_sync_master->get();
                }
            }
        } else {
            // tampilkan dengan yang dihapus
            if($withTrashed==true){
                $data_sync_lokal = $model_lokal::withTrashed()->get();
            }else{
                $data_sync_lokal = $model_lokal::all();
            }
            if ($model_master) {
                // tampilkan dengan yang dihapus
                if($withTrashed==true){
                    $data_sync_master = $model_master::withTrashed()->get();
                } else {
                    $data_sync_master = $model_master::all();
                }
            }
        }
        // dd($tabel_lokal, $tabel_master, compact('data_sync_lokal', 'data_sync_master'));
        return compact('data_sync_lokal', 'data_sync_master');
    }

    /*convert array master index ke key dengan id */
    static public function syncLog(array $dataVar): array
    {
        extract($dataVar);
        if ($log) {
            $sync_insert = isset($log['insert']) ? count($log['insert']) : 0;
            $sync_update = isset($log['update']) ? count($log['update']) : 0;
            $respon['data']['message'] = 'Sync Data update:' . $sync_update . ' insert:' . $sync_insert;
            $respon['data']['sync'] = $log;
        } else {
            $respon['data']['message'] = 'Data Already sync';
            $respon['data']['sync'] = @$data_array_map_master;
        }

        Log::info('user: sys url: ' . url()->current() . ' message: SYNC_MASTER json:' . json_encode($respon));

        return $respon;
    }

    /*convert array master index ke key dengan id */
    static public function sync_update(array $dataVar): array
    {
        extract($dataVar);
        $model_lokal = 'Bangsamu\Master\Models\\' . $tabel_lokal;

        foreach ($sync_update as $keyId) {

            $data_master = $data_array_map_master[$keyId];
            $data_lokal =  $data_array_map_lokal[$keyId];

            /*cek jika berbeda data*/
            $cek_diff = array_diff_assoc($data_master, $data_lokal);

            if (isset($cek_diff['created_at'])) {
                $cek_diff['created_at'] = date('Y-m-d H:i:s', strtotime($cek_diff['created_at']));
            }
            if (isset($cek_diff['updated_at'])) {
                $cek_diff['updated_at'] = date('Y-m-d H:i:s', strtotime($cek_diff['updated_at']));
            }

            if (!empty($cek_diff)) {
                try {
                    // dd(1,!empty($cek_diff),$cek_diff);
                    // $data = @$data_array_map_master[$keyId];
                    // dd($data);
                    $origin = $model_lokal::where('id', $keyId);
                    // $origin = Employee::where('id', $keyId);
                    $origin_data = $origin->first()->toArray();
                    $origin_change = array_intersect_key($origin_data, $cek_diff);
                    // dd($a,$origin_data,$cek_diff,date('d-m-Y', $cek_diff['updated_at']));
                    $origin->update($cek_diff);
                    $log['update'][$keyId]['status'] = 'sukses';
                    // $log['update'][$keyId]['data'] = $cek_diff;
                } catch (\Exception $e) {
                    $log['update'][$keyId]['status'] = 'gagal ' . $e->getMessage();
                }
                $log['update'][$keyId]['data']['origin'] = $origin_change;
                $log['update'][$keyId]['data']['change'] = $cek_diff;
            }
        }
        return $log;
    }

    /*convert array master index ke key dengan id */
    static public function sync_insert(array $dataVar): array
    {
        extract($dataVar);
        $model_lokal = 'Bangsamu\Master\Models\\' . $tabel_lokal;

        foreach ($sync_insert as $keyId) {
            $data = @$data_array_map_master[$keyId];

            if (isset($data['created_at'])) {
                $data['created_at'] = date('Y-m-d H:i:s', strtotime($data['created_at']));
            }
            if (isset($data['updated_at'])) {
                $data['updated_at'] = date('Y-m-d H:i:s', strtotime($data['updated_at']));
            }

            try {
                if (isset($data['id'])) {
                    //jika ada data id pakek insert dengan id
                    $model_lokal::insert($data);
                } else {
                    //insert dengan auto incerment id
                    $model_lokal::create($data);
                }
                $log['insert'][$keyId]['status'] = 'sukses';
            } catch (\Exception $e) {
                $log['insert'][$keyId]['status'] = 'gagal ' . $e->getMessage();
            }
            $log['insert'][$keyId]['data'] = $data;
        }
        return $log;
    }

    /*convert array master index ke key dengan id */
    static public function arrayIndexToId(array $dataVar): array
    {
        // $data_array_map_master=[];
        extract($dataVar);
        // dd(array_keys($master_array[0]),$key_master,$master_array);
        if ($master_array) {
            foreach ($master_array as  $key => $val) {
                /*replace key master ke lokal*/
                if (!$key_master) {
                    $key_master = array_keys(array_shift($master_array));
                }
                if (!$key_lokal) {
                    $key_lokal = $key_master;
                }
                // dd($key_master, $key_lokal);
                foreach ($key_master as $keyM => $valM) {
                    /*replace jika beda*/
                    // dd($key_lokal[$keyM],$valM,$key_lokal,$key_master);
                    if ($key_lokal[$keyM] != $valM) {
                        $val[$key_lokal[$keyM]] = @$val[$valM];/*remaping data*/
                        $data_array_map_master[$val['id']] = $val;
                    }
                    /*ambil map master yg di maping saja*/
                    if (in_array($key_lokal[$keyM], $key_lokal)) {
                        try {
                            // dd($key_lokal[$keyM], $val,$valM);
                            $valMap[$key_lokal[$keyM]] = $val[$valM];
                            $data_array_map_master[$val['id']] = $valMap;
                        } catch (Exception $e) {
                            $return = $e->getMessage();
                            $respond['status'] = 'gagal';
                            $respond['code'] = 400;
                            $respond['data'] = $return;

                            Response::make(self::setOutput($respond))->send();
                            exit();
                        }
                    }
                }
            }
        }

        return $data_array_map_master;
    }

    /**
     * Fungsi untuk cek ip client
     *
     * @return string berisi nilai ip addres dari client
     */
    static public function getIp(): string
    {
        if (isset($_SERVER['HTTP_CLIENT_IP']))
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        else if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        else if (isset($_SERVER['HTTP_X_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
        else if (isset($_SERVER['HTTP_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
        else if (isset($_SERVER['HTTP_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_FORWARDED'];
        else if (isset($_SERVER['REMOTE_ADDR']))
            $ipaddress = $_SERVER['REMOTE_ADDR'];
        else
            $ipaddress = $request->ip();

        return $ipaddress; // it will return the server IP if the client IP is not found using this method.
    }

    /**
     * Fungsi untuk validasi param request
     * jika tidak ada param document maka akan upload activity kemarin
     *
     * @param  \Illuminate\Http\Request  $request
     * @param rules array berisi list data rule yang di harapkan
     *
     * @return mix akan return boolean true jika sukses jika gagal akan respod json untuk data errornya
     */
    static public function validatorCek($request_all, $rules)
    {
        $validator = Validator::make($request_all, $rules);
        if ($validator->fails()) {
            $error = $validator->errors();
            Response::make(self::validateError($error))->send();
            exit();
        }
        return true;
    }

    /**
     * Fungsi untuk kirim validasi format error
     *
     * @param error array berisi list data yang error
     *
     * @return json berisi return dari format static public function setOutput
     */
    static public function validateError($error = null)
    {
        $data['status'] =  'gagal';
        $data['code'] = '400';
        $data['data'] = $error;

        return self::setOutput($data);
    }


    /**
     * Fungsi untuk standart return output respond
     *
     * @param respond mix data bisa json maupun object
     * @param type jenis dari respond yang di harapkan [json,body,object]
     *
     * @return mix respond data dari param type defaultnya json
     */
    static public function setOutput($respon = null, $type = 'json')
    {
        if ($type == 'json') {
            // $return = $respon->{$type}();

            $status = $respon['status'] ?? 'sukses';
            $code = $respon['code'] ?? '200';
            $data = $respon['data'] ?? $respon->object();
            $message = $respon['message'] ?? '';
            $return['status'] = $status;
            $return['code'] = $code;
            $return['data'] = $data;
            $return['message'] = @$message;
            // dd($respon);
        } else {
            $return = $respon->{$type}();
        }
        return $return;
    }

    /**
     * Fungsi menghapus tabel
     *
     * @param nama_tabel string nama tabel yang akan dihapus
     *
     * @return string berisi return status sukses atau gagal dan keterangannya
     */
    static public function downTabel(string $nama_tabel): string
    {
        $drop_tabel = 'gagal';

        if (empty($nama_tabel)) {
            $respond['status'] = 'gagal';
            $respond['code'] = 400;
            $respond['data'] = 'nama_tabel belum di definisikan';

            Response::make(self::setOutput($respond))->send();
            exit();
        }
        // $nama_tabel = $nama_tabel ?? 'webhook';

        /*backup dahulu sebelum di hapus*/
        $dump_tabel = self::ExportDatabase([$nama_tabel]);

        try {
            $drop = Schema::drop($nama_tabel);

            $drop_tabel = 'sukses';
        } catch (\Exception $e) {
            $drop_tabel = 'gagal hapus tabel: ' . $nama_tabel . $e->getMessage();
        }
        return $drop_tabel;
    }

    /**
     * Fungsi untuk melakukan cek route name
     *
     * @param list_route array berisi parammeter list route name
     *
     * @return array berisi validasi cek [sukses,gagal] dan list daftar api yang sukses
     */
    static public function routeCek(array $list_route): array
    {
        $total = count($list_route);
        $sukses = 0;
        $gagal = 0;
        foreach ($list_route as $route) {
            $validate = \Route::has($route);
            $validate_route['validate'][$route] = $validate;
            if ($validate) {
                $validate_route['list'][] = route($route);
                $sukses++;
            } else {
                $gagal++;
            }
        }
        $validate_route['total'] = $total;
        $validate_route['sukses'] = $sukses;
        $validate_route['gagal'] = $gagal;
        return $validate_route;
    }

    /**
     * Fungsi untuk Export/bakup tabel yang akan disimpan ke folder storage/backups/database dengan nama file diikuti suffix unixtime
     *
     * @param tablesToBackup array berisi parammeter list tabel yang akan di dump
     * @param backupFilename string nama backup file
     *
     * @return string berisi return full query yang di dump
     */
    static public function ExportDatabase(array $tablesToBackup = null, string $backupFilename = null): string
    {
        $targetTables = [];
        $newLine = "\n";
        $database = \DB::connection()->getDatabaseName();

        if ($tablesToBackup == null) {
            $queryTables = DB::select(DB::raw('SHOW TABLES'));
            /*limit janagn bakup semua tabel return list tabel saja*/
            dd(json_encode($queryTables));
            foreach ($queryTables as $table) {
                $targetTables[] = $table->{'Tables_in_' . $database};
            }
        } else {
            foreach ($tablesToBackup as $table) {
                $targetTables[] = $table;
            }
        }

        foreach ($targetTables as $table) {
            $content = '';
            $cek_tabel = Schema::hasTable($table);
            if ($cek_tabel) {
                $tableData = DB::select(DB::raw('SELECT * FROM ' . $table));
                $res = DB::select(DB::raw('SHOW CREATE TABLE ' . $table))[0];

                $cnt = 0;
                $content = (!isset($content) ?  '' : $content) . $res->{"Create Table"} . ";" . $newLine . $newLine;
                foreach ($tableData as $row) {
                    $subContent = "";
                    $firstQueryPart = "";
                    if ($cnt == 0 || $cnt % 100 == 0) {
                        $firstQueryPart .= "INSERT INTO {$table} VALUES ";
                        if (count($tableData) > 1) {
                            $firstQueryPart .= $newLine;
                        }
                    }

                    $valuesQuery = "(";
                    foreach ($row as $key => $value) {
                        $valuesQuery .= "'$value'" . ", ";
                    }

                    $subContent = $firstQueryPart . rtrim($valuesQuery, ", ") . ")";

                    if ((($cnt + 1) % 100 == 0 && $cnt != 0) || $cnt + 1 == count($tableData)) {
                        $subContent .= ";" . $newLine;
                    } else {
                        $subContent .= ",";
                    }

                    $content .= $subContent;
                    $cnt++;
                }
            }

            $content .= $newLine;
        }

        $content = trim($content);


        if (is_null($backupFilename)) {
            // return $content;
            $backupFilename = implode('_', $targetTables);
        }

        // dd($targetTables, $backupFilename, $tablesToBackup, $content);

        $dbBackupFile = storage_path('backups/database/');
        if (!File::exists($dbBackupFile)) {
            File::makeDirectory($dbBackupFile, 0755, true);
        }

        $backupFilename = $backupFilename . Carbon::now()->timestamp;
        $dbBackupFile .= "{$backupFilename}.sql";

        $handle = fopen($dbBackupFile, "w+");
        fwrite($handle, $content);
        fclose($handle);
        // dd($content, $dbBackupFile);
        return $content;
    }

    static public function monthly_mp_location($date = null, $type = 'data3', $group = "HEAVY-EQUIPMENT")
    {
        $date = date('Y-m', strtotime($date));
        $data = DB::select("
                        /*HEAVY EQUIPMENT MAN POWER MONTHLY SUMMARY*/
                        with
                        vt_employee as(
                            select
                            he.equipment_location,
                            he.current_location

                            from ams_actions_assign_to_employee aae
                            join ams_employee ae on aae.employee_id=ae.id
                            join ams_heavy_equipment he on aae.asset_number=he.asset_id_old
                            join master_projects p on he.project_id=p.id
                            where aae.group_action=?
                            group by equipment_location
                            order by equipment_location
                        )
                        select * from vt_employee
                    ", [$group]);

        return $data;
    }

    static public function monthly_mp_project($date = null, $type = 'data3', $group = "HEAVY-EQUIPMENT")
    {
        $date = date('Y-m', strtotime($date));
        $data = DB::select("
                        /*HEAVY EQUIPMENT MAN POWER MONTHLY SUMMARY*/
                        with
                        vt_employee as(
                            select
                            he.equipment_location,
                            he.current_location

                            from ams_actions_assign_to_employee aae
                            join ams_employee ae on aae.employee_id=ae.id
                            join ams_heavy_equipment he on aae.asset_number=he.asset_id_old
                            join master_projects p on he.project_id=p.id
                            where aae.group_action=?
                            group by project_name
                            order by project_name
                        )
                        select * from vt_employee
                    ", [$group]);

        return $data;
    }

    static public function monthly_mp($year = null, $month = null)
    {
        $date = date('Y-m', strtotime($year . '-' . $month));
        $data = DB::select("
                        /*HEAVY EQUIPMENT MAN POWER MONTHLY SUMMARY*/
                        with
                        vt_employee as(
                            select
                            DATE_FORMAT(aae.date_added,'%Y-%m') as periode,
                            group_concat(aae.asset_number),
                            count(aae.asset_number) as jml_aset,
                            group_concat(DISTINCT ae.id) as employee_id,
                            count(DISTINCT ae.id) as jml_employee,
                            group_concat(DISTINCT ae.name ORDER BY ae.name ASC SEPARATOR ', ') employee_name,
                            group_concat(DISTINCT  COALESCE(ae.job_title, concat('NA (',p.project_name,')')) ORDER BY ae.job_title ASC SEPARATOR ', ') as position,
                            '' as main_task,
                            aae.additional_notes,
                            he.equipment_location,
                            he.current_location,
                            he.status,
                            -- he.assigned_to,
                            -- he.remark,
                            he.updated_at,
                            p.project_name

                            from ams_actions_assign_to_employee aae
                            join ams_employee ae on aae.employee_id=ae.id
                            join ams_heavy_equipment he on aae.asset_number=he.asset_id_old
                            join master_projects p on he.project_id=p.id
                            where aae.group_action='HEAVY-EQUIPMENT'

                        --     group by ae.employee_id
                        --     group by ae.job_title
                            group by p.project_name ,ae.id
                            order by position
                        ),
                        vt_monthly_mp as(
                            select
                            periode,
                            group_concat(  employee_id) as employee_id,
                            count(employee_id) as jml_mp,
                            position,
                            group_CONCAT(DISTINCT equipment_location) as location,
                            group_CONCAT(DISTINCT project_name) as project_name

                            from vt_employee
                            group by position
                        )
                        select * from vt_employee
                        where periode=?
                        ", [$date]);

        return $data;
    }


    static public function monthly_recap($date = null, $type = 'data3', $group = "HEAVY-EQUIPMENT")
    {
        $date = date('Y-m', strtotime($date));
        $data = DB::select("
                            /*Recap Summary*/
                                select * from ams_sumary
                                where DATE_FORMAT(periode,'%Y-%m')=? and `type`=? and `group`=? and deleted_at is null
                        ", [$date, $type, $group]);

        return $data;
    }

    static public function monthly_condition_status($year = null, $month = null)
    {

        $date = date('Y-m', strtotime($year . '-' . $month));
        $return = DB::select("
                            /*Equipment Condition Status March 2023*/
                            with vt_monthly_task as (
                                select  id,asset_id_old, MONTHNAME(updated_at) MONTHNAME,MONTH(updated_at) MONTH,updated_at,last_updated_by
                                -- ,count(project_name) jml_alat
                                ,project_name,project_id,status,assigned_to,maintenance_work_status,spb ,last_schedule_maintenance_date,next_schedule_maintenance_date,repair_maintenance_date,unavailability_reason,maintenance_issue_description
                                from ams_heavy_equipment
                                -- where project_id is not null
                                -- group by project_name
                                group by asset_id_old
                                order by MONTH asc
                                )
                                select
                                -- status,
                                count(id) jml_unit,
                                COALESCE(unavailability_reason,'Good Condition') condition_status
                                -- ,GROUP_CONCAT(maintenance_issue_description)
                                from vt_monthly_task
                                where DATE_FORMAT(updated_at,'%Y-%m')=?
                                group by unavailability_reason
                                order by jml_unit desc
                        ", [$date]);

        // dd( $return );
        return $return;
    }

    static public function monthly_utilisation($year = null, $month = null)
    {

        $date = date('Y-m', strtotime($year . '-' . $month));
        $return = DB::select("
                            /*Equipment Utilisation March 2023*/
                            with vt_monthly_task as (
                            select  id,asset_id_old,MONTHNAME(updated_at) MONTHNAME,MONTH(updated_at) MONTH,updated_at,last_updated_by,count(project_name) jml_alat,project_name,project_id,status,assigned_to,maintenance_work_status,spb ,last_schedule_maintenance_date,next_schedule_maintenance_date,repair_maintenance_date
                            from ams_heavy_equipment
                            where 1=1
                            -- project_id is not null
                            -- group by project_name
                            group by asset_id_old
                            order by MONTH asc
                            )
                            select
                                count(vtmt.id) as  jml_unit,group_CONCAT(vtmt.id) as list_id
                                ,COALESCE(vtmt.status,'N/A') as condition_status
                                ,group_CONCAT(DISTINCT vtmt.updated_at) as updated_at
                                    ,group_CONCAT(aatp.date_assigned) as date_assigned
                            from vt_monthly_task vtmt
                                    left join ams_actions_assign_to_project aatp on vtmt.asset_id_old=aatp.asset_number
                            where DATE_FORMAT(vtmt.updated_at,'%Y-%m')=?
                                    -- where DATE_FORMAT(aatp.date_assigned,'%Y-%m')=?
                            group by vtmt.status
                        ", [$date]);

        // dd( $return );
        return $return;
    }

    static public function monthly_task($month = null)
    {

        // $query = "SELECT COLUMN_NAME
        //     FROM INFORMATION_SCHEMA.COLUMNS
        //     WHERE TABLE_SCHEMA = '" . config('AMSConfig.APP_DATABASE') . "'
        //     AND IS_NULLABLE = 'NO'
        //     AND COLUMN_KEY != 'PRI'
        //     AND TABLE_NAME = '" . $table . "'";

        // $result = DB::select(DB::raw($query));

        $return = DB::select("
                            with vt_monthly_task as (
                                select  MONTHNAME(updated_at) MONTHNAME,MONTH(updated_at) MONTH,updated_at,last_updated_by,count(project_name) jml_alat,project_name,project_id,status,assigned_to,maintenance_work_status,spb ,last_schedule_maintenance_date,next_schedule_maintenance_date,repair_maintenance_date
                                from ams_heavy_equipment
                                where project_id is not null
                                group by project_name
                                order by MONTH asc
                                )
                                select * from vt_monthly_task
                                where MONTH=?
                            ", [$month]);

        return $return;
    }

    static public function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        // Uncomment one of the following alternatives
        // $bytes /= pow(1024, $pow);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }


    static public function get_config_notif($id)
    {
        $config_notif = NotifConfig::find($id);
        return $config_notif;
    }

    static public function get_group_by_permission()
    {
        /*masih ada bug bekum bisa baca auth harus pakek token*/
        $group = [];
        $auth = \Auth::user();
        $permissions = $auth ? $auth->getAllPermissions()->toArray() : [];
        // array:1 [▼
        // 0 => array:6 [▼
        //         "id" => 1
        //         "name" => "access_qa"
        //         "guard_name" => "web"
        //         "created_at" => null
        //         "updated_at" => null
        //         "pivot" => array:2 [▼
        //         "role_id" => 40
        //         "permission_id" => 1
        //         ]
        //     ]
        // ]
        foreach ($permissions as $keyP => $valP) {
            switch ($valP['name']) {
                case "access_qa":
                    $group[] = 'QA-ASSETS';
                    break;
                case "access_he":
                    $group[] = 'HEAVY-EQUIPMENT';
                    break;
            }
        }
        $group_str = count($group) == 1 ? implode('|', $group) : 'A';
        return $group_str;
    }

    // static public function checkPermission($permission)
    // {
    //     $result = auth()->user()->can($permission);

    //     return $result;
    // }

    static public function getEmployeeByName($label)
    {
        $employee = Employee::select('id', 'name', 'employee_id', 'job_title')->where('name', '=', $label)->first();
        return $employee;
    }

    static public function getProjectByName($label)
    {
        $project = Project::select('id', 'project_name', 'project_code')->where('project_name', '=', $label)->first();
        return $project;
    }

    static public function getProjectById($id)
    {
        $project = Project::select('id', 'project_name', 'project_code')->where('id', '=', $id)->first();
        return $project;
    }

    static public function get_filed_toJson($table)
    {
        $databaseName = \DB::connection()->getDatabaseName();

        $data_json = DB::select("
                        with vt_filed_tabel as(
                            SELECT
                            `COLUMN_NAME`
                            FROM `INFORMATION_SCHEMA`.`COLUMNS`
                            WHERE `TABLE_SCHEMA`='{$databaseName}'
                                AND `TABLE_NAME`='{$table}'
                            )
                            select  CONCAT('{',GROUP_CONCAT(CONCAT('\"',`COLUMN_NAME`,'\"',':true') SEPARATOR ','),'}') as '{$table}'  from vt_filed_tabel
                    ")[0]->$table;
        // dd($type);
        // preg_match("/^enum\(\'(.*)\'\)$/", $type, $matches);
        // $enum = explode("','", $matches[1]);
        return $data_json;
    }

    static public function get_enum_values($table, $field)
    {
        $type = DB::select("SHOW COLUMNS FROM {$table} WHERE Field = '{$field}'")[0]->Type;
        preg_match("/^enum\(\'(.*)\'\)$/", $type, $matches);
        $enum = explode("','", $matches[1]);
        return $enum;
    }

    /**
     * Fungsi untuk alter tabel enum dari tabel lain
     * $table = tabel yg akan di tambahkan enum
     * $field = filed yang akan di set menjadi enum
     * $table_list = tabel yang akan di ambil untuk di jadikan enum
     * $field_list = field yang akan dijadikan nilai enum
     */
    static public function set_enum_values($table, $field, $table_list, $field_list)
    {
        // dd(set_enum_values('ams_heavy_equipment','asset_type','ams_he_at','description'));

        $list_enum = DB::select(" select GROUP_CONCAT(CONCAT(\"'\",{$field_list}, \"'\") SEPARATOR ',') as list_enum from {$table_list}")[0]->list_enum;

        $alter_enum = DB::select("ALTER TABLE {$table} MODIFY COLUMN {$field} ENUM({$list_enum})");

        return $alter_enum;
    }

    static public function get_location_by_id($id)
    {
        $data = DB::table('master_locations')->where('id', $id)->first();

        return $data;
    }

    static public function get_actions_by_name_filter($search, $group, $filter = 'action')
    {
        $data = DB::table('ams_actions')->where('action_group', $group)->where($filter, 'like', '%' . $search . '%')->get();

        return $data;
    }

    static public function get_actions_by_id($id)
    {
        $data = DB::table('ams_actions')->where('id', $id)->first();

        return $data;
    }

    static public function get_group()
    {
        $data = AssetGroup::all();

        return $data;
    }

    static public function get_group_by_name($group)
    {
        $data = DB::table('ams_asset_group')->where('name', $group)->first();

        return $data;
    }

    static public function get_actions_by_name($group)
    {
        $data = DB::table('ams_actions')->where('action_group', $group)->get();

        return $data;
    }

    static public function get_actions_first_by_name($group)
    {
        $data = DB::table('ams_actions')->where('action', $group)->get();

        return $data;
    }

    static public function get_actions_daily_inspection()
    {
        $data = DB::table('ams_actions')->where('action', 'like', 'DAILY-INSPECTION-%')->get();

        return $data;
    }

    static public function get_dailyinspection_required_column($table)
    {
        $query = "SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = '" . config('AMSConfig.APP_DATABASE') . "'
        AND IS_NULLABLE = 'NO'
        AND COLUMN_KEY != 'PRI'
        AND TABLE_NAME = '" . $table . "'";

        $result = DB::select(DB::raw($query));
        $result = array_column($result, 'COLUMN_NAME');

        return $result;
        // return DB::getSchemaBuilder()->getColumnListing($table);
    }

    static public function get_dailyinspection_index_column($table)
    {
        $columns = [
            'added_by',
            'date_added',
            // 'asset_number',
            'brand',
            'capacity',
            'equipment_description',
            'hourmeter',
            'km',
            'location',
            'location_current',
            'maintenance_date',
            'model',
            'serial_number',
        ];

        $query = "SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = '" . config('AMSConfig.APP_DATABASE') . "'
        AND IS_NULLABLE = 'NO'
        AND COLUMN_KEY != 'PRI'
        AND TABLE_NAME = '" . $table . "'";

        $result = DB::select(DB::raw($query));
        $result = array_column($result, 'COLUMN_NAME');

        // append required column (List) from database
        foreach ($result as $column) {
            array_push($columns, $column);
        }

        array_push($columns, 'additional_notes');
        $implode = '"' . implode('","', $columns) . '"';

        // dd($implode, $columns);

        $final = DB::table('ams_daily_inspection_helper_table')
            ->select('field_name', 'description')
            ->whereIn('field_name', $columns)
            ->orderByRaw(DB::raw("FIELD(field_name, " . $implode . ")"))
            ->get();

        return $final;
    }

    static public function get_dailyinspection_description_by_field($name)
    {
        return DB::table('ams_daily_inspection_helper_table')->select('description')->where('field_name', $name)->first()->description;
    }

    static public function get_actions()
    {
        $data = DB::table('ams_actions')->get();

        return $data;
    }

    static public function cron_log_changes($request, $model, $changes, $group, $asset_number)
    {

        unset($changes['updated_at']);

        foreach ($changes as $key => $change) {
            if (!strpos($key, '_id') > 0) {
                Changes::create([
                    'group_id' => $group,
                    'asset_id' => $model->id,
                    'action_id' => null,
                    'asset_number' => $asset_number,
                    'changed_field' => $key,
                    'changed_from' => $change['original'],
                    'changed_to' => $change['changes'],
                    'changed_by' => 'system',
                    'action_name' => 'Update from schedule',
                ]);
            }
        }
    }

    static public function detail_log_changes($request, $model, $original, $group, $data)
    {
        $changes = [];

        foreach ($model->getChanges() as $key => $value) {
            $changes[$key] = [
                'original' => $original[$key],
                'changes' => $value,
            ];
        }

        unset($changes['updated_at']);

        foreach ($changes as $key => $change) {
            if (!strpos($key, '_id') > 0) {
                Changes::create([
                    'group_id' => $group,
                    'asset_id' => $original['id'],
                    'action_id' => 0,
                    'asset_number' => $data['master']['asset_number'],
                    'changed_field' => $key,
                    'changed_from' => $change['original'],
                    'changed_to' => $change['changes'],
                    'changed_by' => Auth::user()->email,
                    'action_name' => 'Update from detail',
                ]);
            }
        }
    }

    static public function log_changes($request, $model, $original, $group, $action, $data)
    {

        $action = Actions::find($action);
        $changes = [];

        foreach ($model->getChanges() as $key => $value) {
            $changes[$key] = [
                'original' => $original[$key],
                'changes' => $value,
            ];
        }

        unset($changes['updated_at']);

        foreach ($changes as $key => $change) {
            if (!strpos($key, '_id') > 0) {
                Changes::create([
                    'group_id' => $group,
                    'asset_id' => $original['id'],
                    'action_id' => $action->id,
                    'asset_number' => $data['master']['asset_number'],
                    'changed_field' => $key,
                    'changed_from' => $change['original'],
                    'changed_to' => $change['changes'],
                    'changed_by' => $request->added_by,
                    'action_name' => $action->action,
                ]);
            }
        }
    }

    static public function insert_attachment($files, $model, $group, $action)
    {
        $action = Actions::find($action);
        $group = AssetGroup::find($group);
        $return = [];

        if ($files) {
            foreach ($files as $file) {
                $prefix = $group->name . '/' . ($model->asset_number ?? $model->id) . '/' . date('Y-m-d');

                $file_manager = FileManager::create([
                    'file_size' => $file->getSize(),
                    'file_type' => $file->getClientMimeType(),
                    'group_action' => $group->name,
                    'created_by' => Auth::user()->id
                ]);

                $file_name = $file_manager->id . "-" . $file->getClientOriginalName();
                $filePath = $file->storeAs('public/attachment/' . $prefix, $file_name, 'local');

                $file_manager->file_name = $file_name;
                $file_manager->file_path = $prefix . '/' . $file_name;
                $file_manager->save();

                array_push($return, $file_manager->id);
            }

            $return = implode(',', $return);

            return $return;
        }
    }

    static public function insert_attachment_gallery_action($files, $model, $group, $action)
    {
        $action = Actions::find($action);
        $group = AssetGroup::find($group);
        $return = [];
        $return_id = [];
        $return_name = [];

        $filetype = 'document';
        $prefix = $filetype;

        if ($files) {
            foreach ($files as $file) {
                $file_manager = Gallery::create([
                    'size' => $file->getSize(),
                    'file_type' => $filetype,
                    'action' => $action->action,
                    'assigned' => $model->asset_number,
                    'user_name' => auth()->user()->email,
                    'header_type' => $file->getClientMimeType(),
                    'path' => 'app/public/gallery/' . $prefix,
                    'link_web' => $group->action_url,
                    'user_id' => auth()->user()->id
                ]);

                $filename = $file_manager->id . "-" . $file->getClientOriginalName();
                $filePath = $file->storeAs('public/gallery/' . $prefix, $filename, 'local');

                $file_manager->filename = $filename;
                $file_manager->url = config('AMSConfig.APP_URL') . 'storage/gallery/' . $prefix . '/' . $filename;
                $file_manager->save();

                array_push($return_id, $file_manager->id);
                array_push($return_name, $file_manager->filename);
            }

            $return_id = array_merge($return_id, str_getcsv($model->document_id));
            $return_name = array_merge($return_name, str_getcsv($model->document_file_name));

            DB::table($group->action_table)->where('id', $model->id)->update([
                'document_id' => implode(',', $return_id),
                'document_file_name' => implode(',', $return_name),
            ]);

            $return = implode(',', $return_name);

            return $return;
        }
    }

    static public function insert_attachment_gallery($file, $request, $action, $model, $group, $asset_number)
    {

        $return_id = [];
        $return_name = [];

        $filetype = 'document';
        $prefix = $filetype;

        $file_manager = Gallery::create([
            'size' => $file->getSize(),
            'file_type' => $filetype,
            'action' => $action,
            'assigned' => $asset_number,
            'user_name' => $request->added_by,
            'header_type' => $file->getClientMimeType(),
            'path' => 'app/public/gallery/' . $prefix,
            'link_web' => $group->action_url,
            // 'user_id' => $request->user_id
        ]);

        $filename = $file_manager->id . "-" . $file->getClientOriginalName();
        $filePath = $file->storeAs('public/gallery/' . $prefix, $filename, 'local');

        $file_manager->filename = $filename;
        $file_manager->url = config('AMSConfig.APP_URL') . 'storage/gallery/' . $prefix . '/' . $filename;
        $file_manager->save();

        array_push($return_id, $file_manager->id);
        array_push($return_name, $file_manager->filename);

        $return_id = array_merge($return_id, str_getcsv($model->document_id));
        $return_name = array_merge($return_name, str_getcsv($model->document_file_name));

        DB::table($group->action_table)->where('id', $model->id)->update([
            'document_id' => implode(',', $return_id),
            'document_file_name' => implode(',', $return_name),
        ]);

        return true;
    }

    static public function deleteFileManager($id) {}

    static public function get_dirty($dirty)
    {
        $array[0] = $dirty;

        $array = array_map('array_filter', $array);
        $array = array_filter($array);

        return $array;
    }

    static public function syncGallery($dir)
    {
        $size = 0;
        $list_file = [];

        foreach (glob(rtrim($dir, '/') . '/*', GLOB_NOSORT) as $key => $each) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $header_type = finfo_file($finfo, $each);
            $filename = basename($each);
            $filesize = filesize($each);
            $list_file[$key]['filesize'] = $filesize;
            $list_file[$key]['filename'] = $filename;
            $list_file[$key]['header_type'] = $header_type;
        }

        return $list_file;
    }

    // static public function cekEvent($data)
    // {
    //     /*event git*/
    //     if (isset($data->result['repository']['name']) || isset($data->result['commits'][0]['message']) || isset($data->result['push']['changes'])) {
    //         $git_branch = $data->result['repository']['name'];
    //         $git_commit = isset($data->result['commits'][0]['message']) ? $data->result['commits'][0]['message'] : $data->result['push']['changes'][0]['commits'][0]['message'];
    //         $git_pull = self::gitPull($git_branch, $git_commit);
    //         $data->git_pull = $git_pull;
    //     } else {
    //         $data = '';
    //     }

    //     return $data;
    // }

    // static public function gitPull($branch, $message)
    // {
    //     $deploy = str_contains($message, '#deploy');
    //     // dd(1, $action, $branch, $message);

    //     if ($deploy) {
    //         $task_file = storage_path('job.json');
    //         $task_list =  File::get($task_file);
    //         $task_list_arry = json_decode($task_list, true);
    //         // dd($task_list_arry);
    //         foreach ($task_list_arry['task'] as $job => $task) {
    //             // dd($task);
    //             if (method_exists($this, 'task_' . $task['type'])) {
    //                 $respond[$job]['script'] =  $task['script'];
    //                 $respond[$job]['respond'] = self::{'task_' . $task['type']}($task['script']);
    //             } else {
    //                 $respond[$job] = 'tidak ada task';
    //             }
    //         }
    //         // dd($respond);
    //     } else {
    //         $respond = 'tidak ada perintah deploy';
    //     }


    //     return $respond;
    // }

    static public function api_token($data)
    {
        // $token = md5($data . ':' . config('SsoConfig.main.KEY'));

        $token = hash_hmac('sha256', $data, config('SsoConfig.main.KEY'));
        return $token;
    }

    static public function validate_token($data, $token)
    {
        if (!is_string($token) || empty($token)) {
            return false;
        }
        $expected = hash_hmac('sha256', $data, config('SsoConfig.main.KEY'));
        return hash_equals($expected, $token);
    }

    static public function convertTableNameToModel($tableName)
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $tableName)));
    }

    static public function resolveModelFromSheetSlug($slug)
    {
        $className = str_replace(' ', '', ucwords(str_replace('_', ' ', $slug)));
        $modelClass = "Bangsamu\\Master\\Models\\" . $className;

        if (!class_exists($modelClass)) {
            // throw new \Exception("Model class [$modelClass] does not exist.");
            abort(403, "Model class [$modelClass] does not exist.");
        }

        return app($modelClass);
    }



    public static function getSettings($key = null, $default = null)
    {
        if (app()->bound('settings')) {
            return app('settings')->get($key, $default);
        }

        return $default;
    }

    //fungsi buat cek ada http / https
    function getFixedUrl($url)
    {

        if (!preg_match('~^https?://~', $url)) {
            $url = url($url);
        }

        return $url;
    }

    // full path storage... file ada bug jika pdf 1.7
    function getTotalPages($file) {
        $checkPdfVersion = $this->checkPdfVersion($file);
        // array:4 [▼ // app/Helpers/Helper.php:755
        //   "version" => "1.7"
        //   "pdf_path" => "/var/www/html/gda-meindo/storage/app/public/requisitions/22/pdf_1.4/686dea14b6759_taxi-25-007-1.pdf"
        //   "pdf_path_1_4" => "/var/www/html/gda-meindo/storage/app/public/requisitions/22/pdf_1.4/686dea14b6759_taxi-25-007-1.pdf"
        //   "pdf_source" => "/var/www/html/gda-meindo/storage/app/public/requisitions/22/686dea14b6759_taxi-25-007-1.pdf"
        // ]
        // dd($checkPdfVersion['pdf_path']);
        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($checkPdfVersion['pdf_path']);
        return $pageCount;

    }

    function checkPdfVersion($source_file, $path_file_new = null)
    {
        // $filename = basename($source_file);

        // dd( $info_file["dirname"] . '/pdf_1.4/' . $info_file["filename"], $info_file);
        // array:4 [▼ // app/Helpers/helpers.php:46
        // "dirname" => "/var/www/html/mr-meindo/storage/app/public/gallery/pdf"
        // "basename" => "10-dummy-v1.6.pdf"
        // "extension" => "pdf"
        // "filename" => "10-dummy-v1.6"
        // ]
        // read pdf file first line because pdf first line contains pdf version information

        if (!file_exists($source_file)) {
            abort(403, "checkPdfVersion not found file::" . $source_file);
        }

        $filepdf = fopen($source_file, "r");
        if ($filepdf) {
            $line_first = fgets($filepdf);
            fclose($filepdf);
        } else {
            echo "error opening the file.";
        }

        // extract number such as 1.4,1.5 from first read line of pdf file
        preg_match_all('!\d+!', $line_first, $matches);

        // save that number in a variable
        $pdfversion = implode('.', $matches[0]);

        // dd($pdfversion,$pdfversion > "1.8");
        if ($pdfversion > "1.4") {

            $info_file = pathinfo($source_file);
            // dd($info_file);
            // array:4 [▼ // app/Helpers/Helper.php:797
            //     "dirname" => "/var/www/html/gda-meindo/storage/app/public/requisitions/22"
            //     "basename" => "686de94d57019_taxi-25-007-1.pdf"
            //     "extension" => "pdf"
            //     "filename" => "686de94d57019_taxi-25-007-1"
            // ]
            $source_file_new = $path_file_new ?? $info_file["dirname"] . '/pdf_1.4/' . $info_file["basename"];

            if (!file_exists($source_file_new)) {
                $path_file = pathinfo($source_file_new);
                // Periksa apakah direktori ada
                if (!is_dir($path_file["dirname"])) {
                    // Jika tidak ada, buat direktori
                    if (mkdir($path_file["dirname"], 0777, true)) {
                        echo 'Direktori $path_file["dirname"] berhasil dibuat.';
                    } else {
                        echo 'Terjadi kesalahan saat membuat direktori ' . $path_file["dirname"];
                    }
                }

                // dd($path_file);
                // dd($source_file_new);
                // /var/www/html/meindo-annotation/storage/app/public/unec-edu-az/pdf-sample-20.pdf
                // echo "tidak bisa edit pdf diatas 1.4 pdf yang akan di edit versi:" . $pdfversion;
                // exit();
                // USE GHOSTSCRIPT IF PDF VERSION ABOVE 1.4 AND SAVE ANY PDF TO VERSION 1.4 , SAVE NEW PDF OF 1.4 VERSION TO NEW PATH
                // dd($source_file_new);
                $run_script = 'gs -dBATCH -dNOPAUSE -dQUIET -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -sOutputFile="' . $source_file_new . '" "' . $source_file . '"';

                // $run_script = 'gs -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -sOutputFile="' . $source_file . '" "' . $source_file . '" version=1.4';
                $a = shell_exec($run_script);
                // echo "<br>run_script:" . $run_script;
                // echo "<br>convert" . $a;
                // exit();
            }
        }
        return [
            'version'=>$pdfversion,
            'pdf_path'=>$source_file_new ?? $source_file,
            'pdf_path_1_4'=>$source_file_new??'',
            'pdf_source'=>$source_file,
        ];
    }


}
