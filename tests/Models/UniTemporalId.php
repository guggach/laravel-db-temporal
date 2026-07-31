<?php

namespace Guggach\LaravelDbTemporal\Tests\Models;

use Guggach\LaravelDbTemporal\Eloquent\IsUniTemporal;
use Guggach\LaravelDbTemporal\Eloquent\UniTemporalModel;
use Illuminate\Database\Eloquent\Model;

class UniTemporalId extends Model implements UniTemporalModel
{
    use IsUniTemporal;

    protected $guarded = [];
}
