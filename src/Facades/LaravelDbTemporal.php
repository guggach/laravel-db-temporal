<?php

namespace Guggach\LaravelDbTemporal\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Guggach\LaravelDbTemporal\LaravelDbTemporal
 */
class LaravelDbTemporal extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Guggach\LaravelDbTemporal\LaravelDbTemporal::class;
    }
}
