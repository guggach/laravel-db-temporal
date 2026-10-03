<?php

use Illuminate\Support\Facades\DB;

/**
 * Regression coverage for the Laravel 13 change to
 * Connection::getNameWithReadWriteType(): it no longer routes through
 * getName() but reads the name straight from `getConfig('name')`. The temporal
 * proxy deliberately receives its config without a `name` key (see
 * TemporalConnection::getName()), so the framework returned NULL. The migrator
 * (Migrator::runMethod()) sets the default connection to that value; a NULL
 * name makes `Arr::get($connections, null)` return the whole connections array,
 * which has no `driver` key — every migration failed with
 * "Undefined array key 'driver'".
 */
beforeEach(function () {
    config()->set('database.connections.temporal', [
        'driver' => 'temporal-proxy',
        'base' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'uni-temporal' => [
            'defaults' => [
                'column_from' => 'known_from',
                'column_to' => 'known_to',
                'max_timestamp' => '9999-12-31 23:59:59',
            ],
            'tables' => [],
        ],
        'bi-temporal' => [
            'defaults' => [],
            'tables' => [],
        ],
    ]);
});

it('exposes the proxy connection name via getName and getNameWithReadWriteType', function () {
    $connection = DB::connection('temporal');

    expect($connection->getName())->toBe('temporal')
        ->and($connection->getNameWithReadWriteType())->toBe('temporal');
});

it('keeps the default connection resolvable after the migrator switches it', function () {
    $connection = DB::connection('temporal');
    $previous = DB::getDefaultConnection();

    try {
        // This is exactly what Migrator::runMethod() does before every
        // migration: point the default connection at the running connection.
        DB::setDefaultConnection($connection->getNameWithReadWriteType());

        expect(config('database.default'))->toBe('temporal')
            ->and(DB::connection()->getName())->toBe('temporal');
    } finally {
        DB::setDefaultConnection($previous);
    }
});

it('appends the read/write type to the proxy connection name', function () {
    $connection = DB::connection('temporal');
    $connection->setReadWriteType('read');

    expect($connection->getNameWithReadWriteType())->toBe('temporal::read');
});
