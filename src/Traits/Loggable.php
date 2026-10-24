<?php

namespace Bangsamu\LibraryClay\Traits;

use Bangsamu\LibraryClay\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Catat created/updated/deleted model ke activity_logs.
 *
 * Model bisa mendefinisikan `protected $loggableIgnore = ['kolom', ...];` untuk kolom yang tidak dicatat
 * (selain atribut $hidden dan kolom updated_at yang selalu diabaikan). Update yang hanya mengubah kolom
 * terabaikan tidak dicatat.
 *
 * Default hanya mencatat request web dengan user login. Set config `libraryclay.activity_log.console = true`
 * agar perubahan dari console (scheduler, artisan, queue worker) juga dicatat sebagai actor system.
 */
trait Loggable
{
    /**
     * Log an activity for this model.
     *
     * @param string $action
     * @param string $description
     * @param array $properties
     * @return ActivityLog
     */
    public function logActivity($action, $description, $properties = [])
    {
        return ActivityLog::log($action, $description, $this, $properties);
    }

    /**
     * Setup model event hooks to automatically log activities.
     */
    public static function bootLoggable()
    {
        static::created(function (Model $model) {
            if ($model->shouldLogActivity()) {
                $model->logActivity(
                    'created',
                    ActivityLog::actorName() . ' created a new ' . class_basename($model),
                    $model->getLoggableAttributes()
                );
            }
        });

        static::updated(function (Model $model) {
            if (!$model->shouldLogActivity() || !$model->wasChanged()) {
                return;
            }

            $changes = $model->withoutIgnoredAttributes($model->getChanges());

            if ($changes === []) {
                return;
            }

            $model->logActivity(
                'updated',
                ActivityLog::actorName() . ' updated ' . class_basename($model),
                [
                    'before' => array_intersect_key($model->getOriginal(), $changes),
                    'after' => $changes
                ]
            );
        });

        static::deleted(function (Model $model) {
            if ($model->shouldLogActivity()) {
                $model->logActivity(
                    'deleted',
                    ActivityLog::actorName() . ' deleted ' . class_basename($model),
                    $model->getLoggableAttributes()
                );
            }
        });
    }

    /**
     * Web: hanya bila ada user login. Console: hanya bila config libraryclay.activity_log.console aktif.
     *
     * @return bool
     */
    public function shouldLogActivity()
    {
        if (app()->runningInConsole()) {
            return ActivityLog::consoleLoggingEnabled();
        }

        return auth()->check();
    }

    /**
     * Get the model attributes to log.
     *
     * @return array
     */
    protected function getLoggableAttributes()
    {
        return $this->withoutIgnoredAttributes($this->toArray());
    }

    /**
     * Buang atribut $hidden, $loggableIgnore, dan kolom updated_at.
     *
     * @param array $attributes
     * @return array
     */
    protected function withoutIgnoredAttributes(array $attributes)
    {
        $ignored = array_merge($this->getHidden(), $this->loggableIgnore ?? []);

        if ($this->usesTimestamps() && $this->getUpdatedAtColumn()) {
            $ignored[] = $this->getUpdatedAtColumn();
        }

        return array_diff_key($attributes, array_flip($ignored));
    }

    /**
     * Get activity logs for this model.
     */
    public function activityLogs()
    {
        return ActivityLog::where('model_type', get_class($this))
            ->where('model_id', $this->getKey())
            ->latest()
            ->get();
    }
}
