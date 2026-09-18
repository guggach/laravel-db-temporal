<?php

namespace Guggach\LaravelDbTemporal\Tests\Models;

use Guggach\LaravelDbTemporal\Eloquent\IsBiTemporalPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;

class MarkedBiPivot extends Pivot
{
    use IsBiTemporalPivot;

    protected $table = 'bi_pivot_probes';
}
