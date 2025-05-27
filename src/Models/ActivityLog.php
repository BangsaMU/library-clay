<?php

namespace Bangsamu\LibraryClay\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Bangsamu\Master\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

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

        $data = [
            'user_id' => $user ? $user->id : null,
            'action' => $action,
            'description' => $description,
            'properties' => $properties,
            'ip_address' => request()->ip(),
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
