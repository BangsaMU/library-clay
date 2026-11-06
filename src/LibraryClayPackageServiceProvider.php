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
        

        // Jadwal telescope:prune harian untuk app yang Telescope-nya aktif. Didaftarkan saat Schedule dibuat
        // (schedule:run, schedule:list, schedule:work), jadi request web dan proses artisan lain tidak menyentuhnya dan
        // tidak menulis log. Dulu didaftarkan di setiap boot + log INFO setiap proses artisan (ribuan baris sehari).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $this->registerTelescopePrune($schedule);
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
     * Daftarkan telescope:prune harian (simpan entri `libraryclay.telescope_prune_hours` jam, bawaan 120 = 5 hari).
     * Dilewati bila jamnya 0, Telescope nonaktif (`telescope.enabled`), atau provider Telescope tidak termuat (mis.
     * Telescope hanya dipasang di local). App yang sudah punya jadwal prune sendiri mengisi 0 di config/libraryclay.php.
     */
    protected function registerTelescopePrune(Schedule $schedule): void
    {
        $hours = (int) config('libraryclay.telescope_prune_hours', 120);
        $provider = 'Laravel\\Telescope\\TelescopeServiceProvider';
        if ($hours <= 0 || ! config('telescope.enabled') || ! class_exists($provider) || ! $this->app->providerIsLoaded($provider)) {
            return;
        }

        $schedule->command('telescope:prune --hours=' . $hours)
            ->daily()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                Log::error('[LibraryClay] Telescope prune gagal dijalankan.');
            });
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
