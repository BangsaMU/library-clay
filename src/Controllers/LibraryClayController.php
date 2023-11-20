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
use App\Models\Gallery;
use App\Models\NotifConfig;

Carbon::setLocale('id');

class LibraryClayController extends Controller
{
    /*convert array master index ke key dengan id */
    static public function getDataSync(array $dataVar): array
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
            } else {
                $data_sync_lokal = $data_sync_lokal->get();
                if ($model_master) {
                    $data_sync_master = $data_sync_master->get();
                }
            }
        } else {
            $data_sync_lokal = $model_lokal::all();
            if ($model_master) {
                $data_sync_master = $model_master::all();
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
            if ($cek_diff) {
                try {

                    $origin = $model_lokal::where('id', $keyId);
                    // $origin = Employee::where('id', $keyId);
                    $origin_data = $origin->first()->toArray();
                    $origin_change = array_intersect_key($origin_data, $cek_diff);
                    // dd($a,$origin_data,$cek_diff);
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
            try {

                $model_lokal::create($data);
                // Employee::create($data);
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

                            Response::make(setOutput($respond))->send();
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
            Response::make(validateError($error))->send();
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

        return setOutput($data);
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
            $return['status'] = $status;
            $return['code'] = $code;
            $return['data'] = $data;
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

            Response::make(setOutput($respond))->send();
            exit();
        }
        // $nama_tabel = $nama_tabel ?? 'webhook';

        /*backup dahulu sebelum di hapus*/
        $dump_tabel = ExportDatabase([$nama_tabel]);

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

    static public function checkPermission($permission)
    {
        $result = auth()->user()->can($permission);

        return $result;
    }

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

    static public function deleteFileManager($id)
    {
    }

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

    static public function cekEvent($data)
    {
        /*event git*/
        if (isset($data->result['repository']['name']) || isset($data->result['commits'][0]['message']) || isset($data->result['push']['changes'])) {
            $git_branch = $data->result['repository']['name'];
            $git_commit = isset($data->result['commits'][0]['message']) ? $data->result['commits'][0]['message'] : $data->result['push']['changes'][0]['commits'][0]['message'];
            $git_pull = self::gitPull($git_branch, $git_commit);
            $data->git_pull = $git_pull;
        } else {
            $data = '';
        }

        return $data;
    }

    static public function gitPull($branch, $message)
    {
        $deploy = str_contains($message, '#deploy');
        // dd(1, $action, $branch, $message);

        if ($deploy) {
            $task_file = storage_path('job.json');
            $task_list =  File::get($task_file);
            $task_list_arry = json_decode($task_list, true);
            // dd($task_list_arry);
            foreach ($task_list_arry['task'] as $job => $task) {
                // dd($task);
                if (method_exists($this, 'task_' . $task['type'])) {
                    $respond[$job]['script'] =  $task['script'];
                    $respond[$job]['respond'] = self::{'task_' . $task['type']}($task['script']);
                } else {
                    $respond[$job] = 'tidak ada task';
                }
            }
            // dd($respond);
        } else {
            $respond = 'tidak ada perintah deploy';
        }


        return $respond;
    }
}
