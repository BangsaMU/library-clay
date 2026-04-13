<?php

namespace Bangsamu\LibraryClay;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Bangsamu\LibraryClay\Middleware\ForceAppUrl;
use Illuminate\Support\Facades\Log; 
 

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
                // 1. Cek apakah Telescope aktif di config
                // 2. Cek apakah Artisan Command Telescope tersedia (menghindari error jika package tidak diinstall)
                if (config('telescope.enabled') && \Illuminate\Support\Facades\Artisan::all()['telescope:prune'] ?? false) {
                    
                    $schedule = $this->app->make(Schedule::class);

                    $schedule->command('telescope:prune --hours=720')
                        ->monthly()
                        ->onOneServer()
                        ->runInBackground()
                        ->onSuccess(function () {
                            Log::info("[LibraryClay] Telescope Prune BERHASIL dijalankan.");
                        })
                        ->onFailure(function () {
                            Log::error("[LibraryClay] Telescope Prune GAGAL dijalankan.");
                        });
                }
            } catch (\Throwable $e) {
                // Diamkan agar tidak merusak aplikasi user jika gagal registrasi schedule
                Log::warning("[LibraryClay] Gagal mendaftarkan Telescope Prune: " . $e->getMessage());
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
