<?php

namespace Guggach\LaravelDbTemporal\Eloquent;

interface UniTemporalModel
{
    public function getColumnTrxFrom(): string;

    public function getColumnTrxTo(): string;

    public function getMaxTimestamp(): string;
}
