<?php

namespace Bangsamu\LibraryClay\Traits;

use Bangsamu\LibraryClay\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

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
            if (!app()->runningInConsole() && auth()->check()) {
                $model->logActivity(
                    'created',
                    auth()->user()->name . ' created a new ' . class_basename($model),
                    $model->getLoggableAttributes()
                );
            }
        });

        static::updated(function (Model $model) {
            if (!app()->runningInConsole() && auth()->check() && $model->wasChanged()) {
                $changes = $model->getChanges();

                // Remove unwanted attributes
                foreach ($model->getHidden() as $hidden) {
                    unset($changes[$hidden]);
                }

                $model->logActivity(
                    'updated',
                    auth()->user()->name . ' updated ' . class_basename($model),
                    [
                        'before' => array_intersect_key($model->getOriginal(), $changes),
                        'after' => $changes
                    ]
                );
            }
        });

        static::deleted(function (Model $model) {
            if (!app()->runningInConsole() && auth()->check()) {
                $model->logActivity(
                    'deleted',
                    auth()->user()->name . ' deleted ' . class_basename($model),
                    $model->getLoggableAttributes()
                );
            }
        });
    }

    /**
     * Get the model attributes to log.
     *
     * @return array
     */
    protected function getLoggableAttributes()
    {
        $attributes = $this->toArray();

        // Remove hidden attributes
        foreach ($this->getHidden() as $hidden) {
            unset($attributes[$hidden]);
        }

        return $attributes;
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
