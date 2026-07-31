<?php

namespace Guggach\LaravelDbTemporal\Eloquent;

interface BiTemporalModel
{
    public function getColumnValidFrom(): string;

    public function getColumnValidTo(): string;

    public function getColumnKnownFrom(): string;

    public function getColumnKnownTo(): string;

    /** @return 'day'|'datetime' */
    public function getVtPrecision(): string;

    /** Returns '9999-12-31' for day precision, '9999-12-31 23:59:59' for datetime. */
    public function getVtMaxSentinel(): string;

    /** Always '9999-12-31 23:59:59' — TT is always dateTime. */
    public function getMaxTimestamp(): string;
}
