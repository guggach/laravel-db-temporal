<?php

namespace Guggach\LaravelDbTemporal\Relations;

use Guggach\LaravelDbTemporal\Eloquent\HasTemporalPivotRelations;
use Guggach\LaravelDbTemporal\Relations\Concerns\InteractsWithTemporalPivot;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * `belongsToMany`-Relation mit temporaler Pivot-Semantik.
 *
 * Wird von {@see HasTemporalPivotRelations}
 * automatisch zurückgegeben; nicht-temporale Pivot-Tabellen verhalten sich
 * exakt wie die Basis-Relation.
 *
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 * @template TPivotModel of \Illuminate\Database\Eloquent\Relations\Pivot
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel, TPivotModel>
 */
class TemporalBelongsToMany extends BelongsToMany
{
    use InteractsWithTemporalPivot;
}
