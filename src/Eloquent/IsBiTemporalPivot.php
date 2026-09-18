<?php

namespace Guggach\LaravelDbTemporal\Eloquent;

/**
 * Marker trait for the pivot model of a bi-temporal pivot table.
 *
 * See {@see IsUniTemporalPivot}. Versioning runs through the relation's
 * BiTemporalBuilder.
 */
trait IsBiTemporalPivot {}
