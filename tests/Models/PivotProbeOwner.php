<?php

namespace Guggach\LaravelDbTemporal\Tests\Models;

use Guggach\LaravelDbTemporal\Eloquent\HasTemporalPivotRelations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class PivotProbeOwner extends Model
{
    use HasTemporalPivotRelations;

    protected $table = 'pivot_probe_owners';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return BelongsToMany<PivotProbeTarget, $this>
     */
    public function targets(): BelongsToMany
    {
        return $this->belongsToMany(PivotProbeTarget::class, 'uni_pivot_probes', 'owner_id', 'target_id');
    }

    /**
     * @return BelongsToMany<PivotProbeTarget, $this>
     */
    public function softTargets(): BelongsToMany
    {
        return $this->belongsToMany(PivotProbeTarget::class, 'uni_pivot_soft_probes', 'owner_id', 'target_id');
    }

    /**
     * Non-temporal pivot table (must behave exactly like standard Laravel).
     *
     * @return BelongsToMany<PivotProbeTarget, $this>
     */
    public function plainTargets(): BelongsToMany
    {
        return $this->belongsToMany(PivotProbeTarget::class, 'plain_pivot_probes', 'owner_id', 'target_id');
    }

    /**
     * Temporal pivot table with `->using()` + marker trait (detection without relying on the schema).
     *
     * @return BelongsToMany<PivotProbeTarget, $this, MarkedUniPivot>
     */
    public function markedTargets(): BelongsToMany
    {
        return $this->belongsToMany(PivotProbeTarget::class, 'uni_pivot_probes', 'owner_id', 'target_id')
            ->using(MarkedUniPivot::class);
    }

    /**
     * Temporal pivot table with a surrogate `id` + SoftDelete (app pattern).
     *
     * @return BelongsToMany<PivotProbeTarget, $this>
     */
    public function idTargets(): BelongsToMany
    {
        return $this->belongsToMany(PivotProbeTarget::class, 'id_pivot_probes', 'owner_id', 'target_id');
    }

    /**
     * Bi-temporal pivot table.
     *
     * @return BelongsToMany<PivotProbeTarget, $this>
     */
    public function biTargets(): BelongsToMany
    {
        return $this->belongsToMany(PivotProbeTarget::class, 'bi_pivot_probes', 'owner_id', 'target_id');
    }

    /**
     * Uni-temporal morph pivot table.
     *
     * @return MorphToMany<PivotProbeTarget, $this>
     */
    public function morphTargets(): MorphToMany
    {
        return $this->morphToMany(PivotProbeTarget::class, 'owner', 'morph_pivot_probes', 'owner_id', 'target_id');
    }
}
