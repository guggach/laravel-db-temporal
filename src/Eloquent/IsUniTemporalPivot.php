<?php

namespace Guggach\LaravelDbTemporal\Eloquent;

/**
 * Marker trait for the pivot model of a uni-temporal pivot table.
 *
 * Apply it to a `->using(MyPivot::class)` model so the temporal relation can
 * detect the mode even when the table is not registered in the connection
 * config. The relation performs the actual versioning through the
 * UniTemporalBuilder; the pivot model only marks the table and exposes its
 * pivot attributes.
 */
trait IsUniTemporalPivot {}
