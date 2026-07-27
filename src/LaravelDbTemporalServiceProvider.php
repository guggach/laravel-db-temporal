<?php

namespace Guggach\LaravelDbTemporal;

use Guggach\LaravelDbTemporal\Connections\TemporalConnection;
use Illuminate\Database\Schema\Blueprint;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelDbTemporalServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-db-temporal');
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerSchemaMacros();

        $db = $this->app['db'];

        $db->extend('temporal-proxy', function ($config, $name) {
            $baseDriver = $config['base'] ?? 'mysql';

            $baseConfig = $config;
            $baseConfig['driver'] = $baseDriver;
            unset($baseConfig['base'], $baseConfig['uni-temporal'], $baseConfig['bi-temporal']);

            $baseConnection = $this->app['db.factory']->make($baseConfig, $name);

            return new TemporalConnection($baseConnection, $config);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\InstallTemporalCommand::class,
                Commands\UninstallTemporalCommand::class,
            ]);
        }
    }

    private function registerSchemaMacros(): void
    {
        Blueprint::macro('unitemporal', function (): void {
            /** @var Blueprint $this */
            $defaults = config('database.connections.'.config('database.default').'.uni-temporal.defaults', []);
            $columnFrom = $defaults['column_from'] ?? 'known_from';
            $columnTo = $defaults['column_to'] ?? 'known_to';
            $this->dateTime($columnFrom);
            $this->dateTime($columnTo);
        });

        Blueprint::macro('unitempIndexes', function (string $pk = 'id'): void {
            /** @var Blueprint $this */
            $defaults = config('database.connections.'.config('database.default').'.uni-temporal.defaults', []);
            $columnFrom = $defaults['column_from'] ?? 'known_from';
            $columnTo = $defaults['column_to'] ?? 'known_to';
            $this->primary([$pk, $columnFrom, $columnTo]);
            $this->index($columnTo);
        });
    }
}
