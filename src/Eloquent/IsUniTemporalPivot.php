<?php

namespace Guggach\LaravelDbTemporal\Eloquent;

/**
 * Marker-Trait für ein Pivot-Model einer uni-temporalen Pivot-Tabelle.
 *
 * Wird an einem `->using(MyPivot::class)`-Model verwendet, damit die
 * temporale Relation den Modus erkennt, ohne dass die Tabelle in der
 * Connection-Config registriert sein muss. Die eigentliche Versionierung
 * macht die Relation über den UniTemporalBuilder — das Pivot-Model dient der
 * Erkennung und dem Zugriff auf die Pivot-Attribute.
 */
trait IsUniTemporalPivot {}
