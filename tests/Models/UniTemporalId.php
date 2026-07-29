<?php

namespace Guggach\LaravelDbTemporal\Tests\Models;

use Guggach\LaravelDbTemporal\Eloquent\IsUniTemporal;
use Guggach\LaravelDbTemporal\Eloquent\UniTemporalModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-use HasFactory<UniTemporalId>
 */
class UniTemporalId extends Model implements UniTemporalModel
{
    use HasFactory;
    use IsUniTemporal;

    protected $guarded = [];
}
