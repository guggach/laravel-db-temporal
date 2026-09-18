<?php

namespace Guggach\LaravelDbTemporal\Relations;

use Guggach\LaravelDbTemporal\Eloquent\HasTemporalPivotRelations;
use Guggach\LaravelDbTemporal\Relations\Concerns\InteractsWithTemporalPivot;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * `belongsToMany` relation with temporal pivot semantics.
 *
 * Returned automatically by {@see HasTemporalPivotRelations}; non-temporal
 * pivot tables behave exactly like the base relation.
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
