<?php

namespace Bangsamu\LibraryClay;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Bangsamu\LibraryClay\Middleware\ForceAppUrl;
use Illuminate\Support\Facades\Log; 
use Illuminate\Support\Facades\Artisan;
 

class LibraryClayPackageServiceProvider extends ServiceProvider
{
    /**
     * The prefix to use for register/load the package resources.
     *
     * @var string
     */
    protected $pkgPrefix = 'LibraryClay';
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if (config('app.env') === 'debug') {
            Log::info('LibraryClayPackageServiceProvider boot called');
        }
        

        $this->app->booted(function () {
            try {
                $cacheKey = 'libraryclay_telescope_prune_registered_' . date('Y-m-d');

                // Jika hari ini sudah didaftarkan, langsung keluar/return
                if (\Illuminate\Support\Facades\Cache::has($cacheKey)&&!$this->app->runningInConsole()) {
                    return;
                }

                // 1. Cek config aktif
                if (config('telescope.enabled')) {
                    $commands = \Illuminate\Support\Facades\Artisan::all();

                    // 2. Cek apakah command telescope:prune tersedia
                    if (isset($commands['telescope:prune'])) {
                        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

                        $schedule->command('telescope:prune --hours=720')
                            ->everyMinute() //  ->daily() atau ->monthly() sesuai kebutuhan
                            ->onOneServer()
                            ->runInBackground()
                            ->onSuccess(function () {
                                \Log::info('Telescope pruning berhasil dijalankan.');
                            })
                            ->onFailure(function () {
                                \Log::error('Telescope pruning gagal!');
                            });

                        // Simpan ke cache agar hit berikutnya di hari yang sama tidak menjalankan blok ini
                        \Illuminate\Support\Facades\Cache::put($cacheKey, true, now()->addDay());

                        \Illuminate\Support\Facades\Log::info("[LibraryClay] Telescope prune didaftarkan (Sekali sehari).");
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("[LibraryClay] Gagal mendaftarkan telescope prune: " . $e->getMessage());
            }
        });



        //
        $this->loadConfig();
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
        $this->publishes([
            __DIR__ . '/../resources/config/LibraryClayConfig.php' => config_path('LibraryClayConfig.php'),
        ]);
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'master');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/master'),
        ]);

        // $this->publishes([
        //     __DIR__.'/../resources/views/' => resource_path('views/adminlte/auth/login.blade.php'),
        // ]);

        $this->publishes([
            __DIR__ . '/routes.php' => base_path('routes/LibraryClay.php'),
        ]);

        // Daftarkan middleware global
        $kernel = $this->app->make(Kernel::class);
        $kernel->pushMiddleware(ForceAppUrl::class);
    }

    /**
     * Load the package config.
     *
     * @return void
     */
    private function loadConfig()
    {
        $configPath = $this->packagePath('resources/config/'.$this->pkgPrefix . 'Config'.'.php');
        $this->mergeConfigFrom($configPath, ucfirst($this->pkgPrefix . 'Config'));
        // dd(config());
    }

    /**
     * Get the absolute path to some package resource.
     *
     * @param  string  $path  The relative path to the resource
     * @return string
     */
    private function packagePath($path)
    {
        return __DIR__ . "/../$path";
    }
}
