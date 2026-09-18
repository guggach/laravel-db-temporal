<?php

namespace Guggach\LaravelDbTemporal\Relations;

use Guggach\LaravelDbTemporal\Relations\Concerns\InteractsWithTemporalPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * `morphToMany` relation with temporal pivot semantics.
 *
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends MorphToMany<TRelatedModel, TDeclaringModel>
 */
class TemporalMorphToMany extends MorphToMany
{
    use InteractsWithTemporalPivot;

    protected function pivotMorphType(): ?string
    {
        return $this->morphType;
    }
}
