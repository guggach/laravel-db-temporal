<?php

namespace Guggach\LaravelDbTemporal\Tests\Models;

use Guggach\LaravelDbTemporal\Eloquent\IsUniTemporal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UniTemporalId extends Model
{
    use HasFactory;
    use IsUniTemporal;


    protected $guarded = [];
}
