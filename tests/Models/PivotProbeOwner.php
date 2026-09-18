<?php

namespace Guggach\LaravelDbTemporal\Tests\Models;

use Guggach\LaravelDbTemporal\Eloquent\HasTemporalPivotRelations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
     * Nicht-temporale Pivot-Tabelle (muss sich exakt wie Standard-Laravel verhalten).
     *
     * @return BelongsToMany<PivotProbeTarget, $this>
     */
    public function plainTargets(): BelongsToMany
    {
        return $this->belongsToMany(PivotProbeTarget::class, 'plain_pivot_probes', 'owner_id', 'target_id');
    }

    /**
     * Temporale Pivot-Tabelle mit `->using()` + Marker-Trait (Erkennung ohne Schema-Zwang).
     *
     * @return BelongsToMany<PivotProbeTarget, $this, MarkedUniPivot>
     */
    public function markedTargets(): BelongsToMany
    {
        return $this->belongsToMany(PivotProbeTarget::class, 'uni_pivot_probes', 'owner_id', 'target_id')
            ->using(MarkedUniPivot::class);
    }

    /**
     * Temporale Pivot-Tabelle mit Surrogat-`id` + SoftDelete (App-Muster).
     *
     * @return BelongsToMany<PivotProbeTarget, $this>
     */
    public function idTargets(): BelongsToMany
    {
        return $this->belongsToMany(PivotProbeTarget::class, 'id_pivot_probes', 'owner_id', 'target_id');
    }

    /**
     * Bi-temporale Pivot-Tabelle.
     *
     * @return BelongsToMany<PivotProbeTarget, $this>
     */
    public function biTargets(): BelongsToMany
    {
        return $this->belongsToMany(PivotProbeTarget::class, 'bi_pivot_probes', 'owner_id', 'target_id');
    }
}
