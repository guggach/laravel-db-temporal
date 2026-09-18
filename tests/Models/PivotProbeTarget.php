<?php

namespace Guggach\LaravelDbTemporal\Tests\Models;

use Illuminate\Database\Eloquent\Model;

class PivotProbeTarget extends Model
{
    protected $table = 'pivot_probe_targets';

    protected $guarded = [];

    public $timestamps = false;
}
