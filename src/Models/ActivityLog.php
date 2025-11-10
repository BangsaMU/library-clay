<?php

namespace Bangsamu\LibraryClay\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Bangsamu\Master\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ActivityLog extends Model
{
    use HasFactory;

    public $table = "activity_logs";
    protected $guarded = [];


    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'description',
        'properties',
        'ip_address',
        'user_agent',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'properties' => 'json',
    ];

    /**
     * Get the user that performed the action.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the related model if available.
     */
    public function subject()
    {
        if (!$this->model_type || !$this->model_id) {
            return null;
        }

        return app($this->model_type)->find($this->model_id);
    }

    /**
     * Create a new activity log.
     *
     * @param string $action
     * @param string $description
     * @param Model|null $model
     * @param array $properties
     * @return ActivityLog
     */
    public static function log($action, $description, $model = null, $properties = [])
    {
        $user = auth()->user();
        // =============================
        // 1️⃣ Dapatkan IP Asli (Real Client IP)
        // =============================

        $ip = self::getClientPublicIp(request());

        $data = [
            'user_id' => $user ? $user->id : null,
            'action' => $action,
            'description' => $description,
            'properties' => $properties,
            'ip_address' => $ip,
            'user_agent' => substr(request()->userAgent(), 0, 255),
        ];

        if ($model) {
            $data['model_type'] = get_class($model);
            $data['model_id'] = $model->getKey();
        }

        return static::create($data);
    }

    /**
     * Get a shortened model name for display.
     */
    public function getModelNameAttribute()
    {
        if (!$this->model_type) {
            return null;
        }

        $parts = explode('\\', $this->model_type);
        return end($parts);
    }

    /**
     * Get a formatted date for display.
     */
    public function getFormattedDateAttribute()
    {
        return $this->created_at->format('M d, Y h:i A');
    }


    /**
     * Ambil IP publik dari request atau fallback dari layanan eksternal
     */
    protected static function getClientPublicIp($request): string
    {
        // 1️⃣ Ambil IP dari header proxy dulu
        $ip = $request->header('X-Forwarded-For')
            ?? $request->header('X-Real-IP')
            ?? $request->ip();

        // 2️⃣ Jika IP private, ambil IP publik server (cache 6 jam)
        if (self::isPrivateIp($ip)) {
            return cache()->remember('server_public_ip', now()->addHours(6), function () {
                try {
                    // 🌩️ Coba ambil dari Cloudflare
                    $response = Http::timeout(3)->get('https://cloudflare.com/cdn-cgi/trace');

                    if ($response->successful()) {
                        preg_match('/ip=([0-9a-fA-F:.]+)/', $response->body(), $matches);
                        if (!empty($matches[1]) && filter_var($matches[1], FILTER_VALIDATE_IP)) {
                            return $matches[1];
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Gagal ambil IP publik dari Cloudflare: ' . $e->getMessage());
                }

                // 🌍 Fallback ke ipify jika Cloudflare gagal
                try {
                    $response = Http::timeout(3)->get('https://api.ipify.org');
                    if ($response->successful()) {
                        $publicIp = trim($response->body());
                        if (filter_var($publicIp, FILTER_VALIDATE_IP)) {
                            return $publicIp;
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Gagal ambil IP publik dari ipify: ' . $e->getMessage());
                }

                // 🚫 Jika semua gagal
                return '0.0.0.0';
            });
        }

        return $ip;
    }


    /**
     * Cek apakah IP adalah IP lokal/private
     */
    protected static function isPrivateIp($ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    protected static $hasCheckedTable = false;


    protected static function boot()
    {
        parent::boot();

        if (!self::$hasCheckedTable) {
            self::$hasCheckedTable = true;
            if (!Schema::hasTable((new static)->getTable())) {
                Schema::create((new static)->getTable(), function (Blueprint $table) {
                    $table->bigIncrements('id');
                    $table->unsignedBigInteger('user_id')->nullable();
                    $table->string('action');
                    $table->string('model_type')->nullable();
                    $table->unsignedBigInteger('model_id')->nullable();
                    $table->text('description');
                    $table->longText('properties')->nullable()->collation('utf8mb4_bin');
                    $table->string('ip_address')->nullable();
                    $table->string('user_agent')->nullable();
                    $table->timestamps();
                });
            }
        }
    }
}
