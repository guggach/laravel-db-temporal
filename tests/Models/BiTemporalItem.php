<?php

namespace Guggach\LaravelDbTemporal\Tests\Models;

use Guggach\LaravelDbTemporal\Eloquent\BiTemporalModel;
use Guggach\LaravelDbTemporal\Eloquent\IsBiTemporal;
use Illuminate\Database\Eloquent\Model;

class BiTemporalItem extends Model implements BiTemporalModel
{
    use IsBiTemporal;

    protected $table = 'bi_temporal_items';

    protected $guarded = [];

    public $incrementing = false;

    const VT_PRECISION = 'day';
}
