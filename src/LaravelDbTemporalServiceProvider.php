<?php

namespace Guggach\LaravelDbTemporal;

use Guggach\LaravelDbTemporal\Configuration\TemporalConfig;
use Guggach\LaravelDbTemporal\Connections\TemporalConnection;
use Illuminate\Database\Schema\Blueprint;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * @phpstan-import-type TemporalDefaultsShape from TemporalConfig
 */
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

        $db = $this->app->make('db');
        $db->extend('temporal-proxy', function (array $config, string $name) {
            $baseDriver = $config['base'] ?? 'mysql';

            $baseConfig = $config;
            $baseConfig['driver'] = $baseDriver;
            unset($baseConfig['base'], $baseConfig['uni-temporal'], $baseConfig['bi-temporal']);

            $baseConnection = $this->app->make('db.factory')->make($baseConfig, $name);

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
            $config = LaravelDbTemporalServiceProvider::resolveTemporalDefaults();
            $this->dateTime($config->columnFrom);
            $this->dateTime($config->columnTo);
        });

        Blueprint::macro('unitempIndexes', function (string $pk = 'id'): void {
            /** @var Blueprint $this */
            $config = LaravelDbTemporalServiceProvider::resolveTemporalDefaults();
            $this->primary([$pk, $config->columnFrom, $config->columnTo]);
            $this->index([$config->columnTo, $pk]);
        });
    }

    public static function resolveTemporalDefaults(): TemporalConfig
    {
        $defaultConnection = config('database.default');
        $connectionName = is_string($defaultConnection) ? $defaultConnection : '';

        $configured = config('database.connections.'.$connectionName.'.uni-temporal.defaults', []);

        if (! is_array($configured)) {
            return TemporalConfig::fromArray(null);
        }

        /** @var TemporalDefaultsShape $configured */
        return TemporalConfig::fromArray($configured);
    }
}
