<?php

namespace Bangsamu\LibraryClay;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Bangsamu\LibraryClay\Middleware\ForceAppUrl;

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
