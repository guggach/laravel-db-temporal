<?php

namespace Guggach\LaravelDbTemporal\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected $enablesPackageDiscoveries = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Factory::guessFactoryNamesUsing(
        //     fn (string $modelName) => 'Guggach\\LaravelDbTemporal\\Database\\Factories\\'.class_basename($modelName).'Factory'
        // );
    }

    protected function getPackageProviders($app)
    {
        return [
            //            'Illuminate\Database\DatabaseServiceProvider',
            'Guggach\LaravelDbTemporal\LaravelDbTemporalServiceProvider',
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            //            'DB' => 'Illuminate\Database\DatabaseManager',
        ];
    }

    public function defineEnvironment($app)
    {
        // config()->set('database.default', 'testing');

        /*
        $migration = include __DIR__.'/../database/migrations/create_laravel-db-temporal_table.php.stub';
        $migration->up();
        */
        // Setup default database to use sqlite :memory:

        config()->set('app.key', 'base64:EWcFBKBT8lKlGK8nQhTHY+wg19QlfmbhtO9Qnn3NfcA=');

        config()->set('database.default', 'sqlite-test');
        config()->set('database.connections.sqlite-test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        // $this->loadLaravelMigrations();
        // $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        // $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        // $this->artisan('migrate', ['--database' => 'testbench'])->run();
    }
}
