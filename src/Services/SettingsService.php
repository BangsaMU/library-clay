<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

class SettingsService
{
    /**
     * Get a setting value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        // Check config first (in case it was overridden)
        if (strpos($key, '.') !== false && Config::has($key)) {
            return Config::get($key);
        }

        // Get from settings
        $setting = Setting::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    /**
     * Set a setting value
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set($key, $value)
    {
        $setting = Setting::firstOrCreate(['key' => $key]);
        $setting->value = $value;
        $setting->save();

        // Update the config if needed
        if (strpos($key, '.') !== false) {
            Config::set($key, $value);
        }

        // Clear the cache
        Cache::forget('settings');
    }
}
