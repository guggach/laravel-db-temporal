<?php

namespace Guggach\LaravelDbTemporal\Tests\Models;

use Guggach\LaravelDbTemporal\Eloquent\IsUniTemporalPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;

class MarkedUniPivot extends Pivot
{
    use IsUniTemporalPivot;

    protected $table = 'uni_pivot_probes';
}
