<?php

namespace Guggach\LaravelDbTemporal\Eloquent;

/**
 * Marker-Trait für ein Pivot-Model einer bi-temporalen Pivot-Tabelle.
 *
 * Siehe {@see IsUniTemporalPivot}. Die Versionierung läuft über den
 * BiTemporalBuilder der Relation.
 */
trait IsBiTemporalPivot {}
