<?php

namespace Guggach\LaravelDbTemporal;

use Guggach\LaravelDbTemporal\Commands\LaravelDbTemporalCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelDbTemporalServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-db-temporal')
            ->hasConfigFile('db-temporal');

        //            ->hasMigration('create_laravel-db-temporal_table')
        //            ->hasCommand(LaravelDbTemporalCommand::class);
    }

    public function register()
    {

        parent::register();

        // $app = $this->app;

        // $this->app->resolving('db', function ($db) use ($app) {
        //     /** @var DatabaseManager $db */
        //     $db->extend('bitemp', function ($config, $name) use ($app) {

        //         $pdoConnection = (new ODBCConnector())->connect($config);
        //         $connection = new ODBCConnection($pdoConnection, $config['database'], isset($config['prefix']) ? $config['prefix'] : '', $config);
        //         return $connection;
        //     });
        // });
    }
}
