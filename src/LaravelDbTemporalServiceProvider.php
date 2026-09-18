<?php

namespace Guggach\LaravelDbTemporal;

use Guggach\LaravelDbTemporal\Configuration\BiTemporalConfig;
use Guggach\LaravelDbTemporal\Configuration\TemporalConfig;
use Guggach\LaravelDbTemporal\Connections\TemporalConnection;
use Illuminate\Database\Schema\Blueprint;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * @phpstan-import-type TemporalDefaultsShape from TemporalConfig
 * @phpstan-import-type TemporalConnectionConfigShape from TemporalConfig
 * @phpstan-import-type BiTemporalConfigShape from BiTemporalConfig
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
            /** @var TemporalConnectionConfigShape $config */
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
                Commands\MakePivotMigrationCommand::class,
            ]);
        }
    }

    private function registerSchemaMacros(): void
    {
        Blueprint::macro('unitemporal', function (): void {
            /** @var Blueprint $this */
            $config = LaravelDbTemporalServiceProvider::resolveTemporalDefaults();
            // Precision 6 (microseconds) is required: transaction timestamps
            // are written with microseconds and two versions in the same
            // second must not collide on the composite primary key.
            $this->dateTime($config->columnFrom, 6);
            $this->dateTime($config->columnTo, 6);
        });

        Blueprint::macro('unitempIndexes', function (string $pk = 'id'): void {
            /** @var Blueprint $this */
            $config = LaravelDbTemporalServiceProvider::resolveTemporalDefaults();
            $this->primary([$pk, $config->columnFrom, $config->columnTo]);
            $this->index([$config->columnTo, $pk]);
        });

        Blueprint::macro('bitemporal', function (): void {
            /** @var Blueprint $this */
            $config = LaravelDbTemporalServiceProvider::resolveBiTemporalDefaults();
            if ($config->vtPrecision === 'datetime') {
                $this->dateTime($config->columnValidFrom, 6);
                $this->dateTime($config->columnValidTo, 6);
            } else {
                $this->date($config->columnValidFrom);
                $this->date($config->columnValidTo);
            }
            $this->dateTime($config->columnKnownFrom, 6);
            $this->dateTime($config->columnKnownTo, 6);
        });

        Blueprint::macro('bitempIndexes', function (string $pk = 'id'): void {
            /** @var Blueprint $this */
            $config = LaravelDbTemporalServiceProvider::resolveBiTemporalDefaults();
            $this->primary([$pk, $config->columnValidTo, $config->columnKnownTo]);
            $this->index([$config->columnKnownTo, $config->columnValidTo, $pk]);
            $this->index([$config->columnValidTo, $config->columnKnownTo, $pk]);
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

    public static function resolveBiTemporalDefaults(): BiTemporalConfig
    {
        $defaultConnection = config('database.default');
        $connectionName = is_string($defaultConnection) ? $defaultConnection : '';

        $configured = config('database.connections.'.$connectionName.'.bi-temporal.defaults', []);

        if (! is_array($configured)) {
            return BiTemporalConfig::fromArray(null);
        }

        /** @var BiTemporalConfigShape $configured */
        return BiTemporalConfig::fromArray($configured);
    }
}
