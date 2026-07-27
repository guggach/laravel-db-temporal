<?php

namespace Guggach\LaravelDbTemporal;

use Guggach\LaravelDbTemporal\Connections\TemporalConnection;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelDbTemporalServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-db-temporal')
            ->hasConfigFile('db-temporal');
    }

    public function boot(): void
    {
        $db = $this->app['db'];

        $db->extend('temporal-proxy', function ($config, $name) {
            $baseDriver = $config['base'] ?? 'mysql';

            $baseConfig = $config;
            $baseConfig['driver'] = $baseDriver;
            unset($baseConfig['base'], $baseConfig['uni-temporal'], $baseConfig['bi-temporal']);

            $baseConnection = $this->app['db.factory']->make($baseConfig, $name);

            return new TemporalConnection($baseConnection, $config);
        });
    }
}
