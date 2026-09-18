<?php

namespace Guggach\LaravelDbTemporal\Relations\Concerns;

use Carbon\Carbon;
use Guggach\LaravelDbTemporal\Configuration\BiTemporalConfig;
use Guggach\LaravelDbTemporal\Configuration\TemporalConfig;
use Guggach\LaravelDbTemporal\Connections\TemporalConnection;
use Guggach\LaravelDbTemporal\Database\Query\BiTemporalBuilder;
use Guggach\LaravelDbTemporal\Database\Query\UniTemporalBuilder;
use Guggach\LaravelDbTemporal\Eloquent\IsBiTemporal;
use Guggach\LaravelDbTemporal\Eloquent\IsBiTemporalPivot;
use Guggach\LaravelDbTemporal\Eloquent\IsUniTemporal;
use Guggach\LaravelDbTemporal\Eloquent\IsUniTemporalPivot;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use LogicException;

/**
 * Temporal pivot semantics for a `BelongsToMany`/`MorphToMany` relation.
 *
 * The pivot table stays a "normal" Laravel pivot table but is versioned:
 * reads return the current state only, `attach` inserts a new version,
 * `detach`/`updateExistingPivot` close the current version (and set the soft
 * delete column when present). Non-temporal pivot tables delegate unchanged
 * to the base relation.
 *
 * Detection order: marker trait on the `using` model, then the connection
 * config (uni-/bi-temporal.tables), then the schema (`known_from`/`known_to`,
 * optionally `valid_from`/`valid_to`).
 */
trait InteractsWithTemporalPivot
{
    protected bool $pivotTemporalResolved = false;

    protected ?string $pivotTemporalMode = null;

    protected bool $pivotSoftDeletes = false;

    protected bool $pivotHasSingleId = false;

    protected ?TemporalConfig $pivotUniConfig = null;

    protected ?BiTemporalConfig $pivotBiConfig = null;

    /**
     * Base query (join + foreign key) without the default current filters,
     * kept for as-of reads.
     *
     * @var EloquentBuilder<*>|null
     */
    protected ?EloquentBuilder $pivotBaseQuery = null;

    /**
     * Column listing per connection|table (avoids repeated schema lookups on
     * every relation construction).
     *
     * @var array<string, array<int, string>>
     */
    protected static array $temporalPivotColumnCache = [];

    /**
     * @param  Builder|EloquentBuilder<*>  $query
     */
    protected function applyCurrentPivotConstraints(Builder|EloquentBuilder $query): void
    {
        $query->where($this->table.'.'.$this->pivotKnownTo(), $this->pivotMaxTimestamp());

        if ($this->pivotSoftDeletes) {
            $query->whereNull($this->table.'.deleted_at');
        }

        if ($this->pivotTemporalMode === 'bi') {
            $config = $this->biConfig();

            $today = $config->vtPrecision === 'datetime'
                ? now()->format('Y-m-d H:i:s')
                : now()->format('Y-m-d').' 00:00:00';

            $query->where($this->table.'.'.$config->columnValidFrom, '<=', $today)
                ->where($this->table.'.'.$config->columnValidTo, '>=', $today);
        }
    }

    protected function resolvePivotTemporal(): void
    {
        if ($this->pivotTemporalResolved) {
            return;
        }

        $this->pivotTemporalResolved = true;

        $connection = $this->parent->getConnection();
        $cacheKey = $connection->getName().'|'.$this->table;

        if (! isset(static::$temporalPivotColumnCache[$cacheKey])) {
            static::$temporalPivotColumnCache[$cacheKey] = $connection
                ->getSchemaBuilder()
                ->getColumnListing($this->table);
        }

        $columns = static::$temporalPivotColumnCache[$cacheKey];
        $this->pivotHasSingleId = in_array('id', $columns, true);
        $softDeletes = in_array('deleted_at', $columns, true);

        $mode = null;

        if ($this->using !== null) {
            $uses = class_uses_recursive($this->using);

            if (in_array(IsBiTemporalPivot::class, $uses, true) || in_array(IsBiTemporal::class, $uses, true)) {
                $mode = 'bi';
            } elseif (in_array(IsUniTemporalPivot::class, $uses, true) || in_array(IsUniTemporal::class, $uses, true)) {
                $mode = 'uni';
            }

            if (in_array(SoftDeletes::class, $uses, true)) {
                $softDeletes = true;
            }
        }

        if ($connection instanceof TemporalConnection) {
            $bi = $connection->getBiTemporalTableConfig($this->table);
            $uni = $connection->getUniTemporalTableConfig($this->table);

            if ($bi !== null) {
                $mode = 'bi';
                $this->pivotBiConfig = $bi;
            } elseif ($uni !== null) {
                $mode = 'uni';
                $this->pivotUniConfig = $uni;
            }
        }

        if ($mode === null && in_array('known_from', $columns, true) && in_array('known_to', $columns, true)) {
            $mode = in_array('valid_from', $columns, true) && in_array('valid_to', $columns, true) ? 'bi' : 'uni';
        }

        if ($mode === 'uni' && $this->pivotUniConfig === null) {
            $this->pivotUniConfig = TemporalConfig::fromArray(null);
        }

        if ($mode === 'bi' && $this->pivotBiConfig === null) {
            $this->pivotBiConfig = BiTemporalConfig::fromArray(null);
        }

        $this->pivotTemporalMode = $mode;
        $this->pivotSoftDeletes = $mode !== null && $softDeletes;
    }

    protected function pivotIsTemporal(): bool
    {
        $this->resolvePivotTemporal();

        return $this->pivotTemporalMode !== null;
    }

    protected function uniConfig(): TemporalConfig
    {
        return $this->pivotUniConfig ?? throw new LogicException('Uni-temporal pivot config was not resolved.');
    }

    protected function biConfig(): BiTemporalConfig
    {
        return $this->pivotBiConfig ?? throw new LogicException('Bi-temporal pivot config was not resolved.');
    }

    protected function pivotKnownTo(): string
    {
        return $this->pivotTemporalMode === 'bi'
            ? $this->biConfig()->columnKnownTo
            : $this->uniConfig()->columnTo;
    }

    protected function pivotMaxTimestamp(): string
    {
        return $this->pivotTemporalMode === 'bi'
            ? $this->biConfig()->maxTimestamp
            : $this->uniConfig()->maxTimestamp;
    }

    /**
     * Select the temporal columns in addition to the configured pivot columns
     * so `->pivot` exposes them.
     *
     * @return array<int, string>
     */
    protected function pivotTemporalPivotColumns(): array
    {
        if ($this->pivotTemporalMode === 'bi') {
            $config = $this->biConfig();
            $columns = [
                $config->columnValidFrom,
                $config->columnValidTo,
                $config->columnKnownFrom,
                $config->columnKnownTo,
            ];
        } else {
            $config = $this->uniConfig();
            $columns = [
                $config->columnFrom,
                $config->columnTo,
            ];
        }

        if ($this->pivotSoftDeletes) {
            $columns[] = 'deleted_at';
        }

        return $columns;
    }

    protected function newTemporalPivotBuilder(): Builder
    {
        $connection = $this->parent->getConnection();

        if ($this->pivotTemporalMode === 'bi') {
            $builder = new BiTemporalBuilder(
                $connection,
                $connection->getQueryGrammar(),
                $connection->getPostProcessor(),
            );
            $builder->setTemporalConfig($this->biConfig(), calledByEloquent: false);
        } else {
            $builder = new UniTemporalBuilder(
                $connection,
                $connection->getQueryGrammar(),
                $connection->getPostProcessor(),
            );
            $this->uniConfig()->applyToBuilder($builder, calledByEloquent: false);
        }

        return $builder->from($this->table);
    }

    public function newPivotStatement(): Builder
    {
        $this->resolvePivotTemporal();

        if ($this->pivotTemporalMode === null) {
            return parent::newPivotStatement();
        }

        return $this->newTemporalPivotBuilder();
    }

    public function newPivotQuery(): Builder
    {
        $this->resolvePivotTemporal();

        $query = parent::newPivotQuery();

        if ($this->pivotTemporalMode !== null) {
            $this->applyCurrentPivotConstraints($query);
        }

        return $query;
    }

    public function addConstraints(): void
    {
        parent::addConstraints();

        if (! $this->pivotIsTemporal()) {
            return;
        }

        // Keep the base query for as-of before the default current filters are applied.
        $this->pivotBaseQuery = clone $this->query;

        $this->applyCurrentPivotConstraints($this->query);

        $this->pivotColumns = array_values(array_unique(
            array_merge($this->pivotColumns, $this->pivotTemporalPivotColumns()),
            SORT_REGULAR,
        ));
    }

    /**
     * `$ids` is the related side (values of `$relatedPivotKey`, e.g. the
     * target ids) — the foreign side (`$foreignPivotKey`, e.g. `owner_id`) is
     * taken from the parent model automatically. A composite identity
     * `(foreign, related)` is therefore built from the parent plus `$ids`;
     * additional pivot columns (role, primary, …) belong in `$attributes`.
     *
     * @param  mixed  $ids
     * @param  array<string, mixed>  $attributes
     */
    public function attach($ids, array $attributes = [], $touch = true): void
    {
        $this->resolvePivotTemporal();

        if ($this->pivotTemporalMode === null) {
            parent::attach($ids, $attributes, $touch);

            return;
        }

        /** @var array<int, array<string, mixed>> $records */
        $records = $this->formatAttachRecords($this->parseIds($ids), $attributes);
        $builder = $this->newTemporalPivotBuilder();

        foreach ($records as $record) {
            if ($this->pivotHasSingleId && ! array_key_exists('id', $record)) {
                $builder->insertGetId($record, 'id');
            } else {
                $builder->insert($record);
            }
        }

        if ($touch) {
            $this->touchIfTouching();
        }
    }

    /**
     * `$ids` is the related side (values of `$relatedPivotKey`); `null`
     * removes all of the parent's links. The foreign side is taken from the
     * parent model.
     *
     * @param  mixed  $ids
     */
    public function detach($ids = null, $touch = true): int
    {
        $this->resolvePivotTemporal();

        if ($this->pivotTemporalMode === null) {
            return parent::detach($ids, $touch);
        }

        $query = $this->newPivotQuery();

        if (! is_null($ids)) {
            $ids = $this->parseIds($ids);

            if (empty($ids)) {
                return 0;
            }

            $query->whereIn($this->getQualifiedRelatedPivotKeyName(), (array) $ids);
        }

        if ($this->pivotTemporalMode === 'bi' && $query instanceof BiTemporalBuilder) {
            // Bi-temporal: close the validity (VT) instead of soft deleting.
            $results = $query->closeValidityAt();
        } elseif ($this->pivotSoftDeletes) {
            $results = $query->update(['deleted_at' => now()]);
        } else {
            $results = $query->delete();
        }

        if ($touch) {
            $this->touchIfTouching();
        }

        return $results;
    }

    /**
     * @param  mixed  $id
     * @param  array<string, mixed>  $attributes
     */
    public function updateExistingPivot($id, array $attributes, $touch = true): int
    {
        $this->resolvePivotTemporal();

        if ($this->pivotTemporalMode === null) {
            return parent::updateExistingPivot($id, $attributes, $touch);
        }

        $values = $this->castPivotAttributes($attributes);

        if ($this->hasPivotColumn($this->updatedAt())) {
            $values = $this->castPivotAttributes($this->addTimestampsToAttachment($attributes, true));
        }

        $updated = $this->newPivotStatementForId($id)->update($values);

        if ($touch) {
            $this->touchIfTouching();
        }

        return $updated;
    }

    /**
     * Bi-temporal as-of access: `$validAt` is the business date, `$knownAt`
     * the knowledge date (default now). Without `$validAt` only the
     * transaction time is filtered. Bi-temporal pivots only.
     *
     * Note: rebuilds the relation query and therefore does not carry over
     * extra constraints from the relation definition.
     */
    public function asOf(Carbon|string|null $validAt = null, Carbon|string|null $knownAt = null): static
    {
        $this->resolvePivotTemporal();

        if ($this->pivotTemporalMode !== 'bi') {
            throw new LogicException('asOf() is only available for bi-temporal pivot tables.');
        }

        if ($this->pivotBaseQuery !== null) {
            // Take only the base query (join + FK), without the default current filters.
            $this->query->setQuery(clone $this->pivotBaseQuery->getQuery());
        }

        $config = $this->biConfig();
        $known = $knownAt !== null ? ($knownAt instanceof Carbon ? $knownAt : new Carbon($knownAt)) : new Carbon;

        $this->query
            ->where($this->table.'.'.$config->columnKnownFrom, '<=', $known->format('Y-m-d H:i:s'))
            ->where($this->table.'.'.$config->columnKnownTo, '>=', $known->format('Y-m-d H:i:s'));

        if ($validAt !== null) {
            $this->applyValidRange($validAt instanceof Carbon ? $validAt : new Carbon($validAt));
        }

        return $this;
    }

    /** Valid state at the given date, as known now. */
    public function validAsOf(Carbon|string $validAt): static
    {
        return $this->asOf($validAt);
    }

    /** State at the given knowledge date (no validity filter). */
    public function knownAsOf(Carbon|string $knownAt): static
    {
        return $this->asOf(null, $knownAt);
    }

    private function applyValidRange(Carbon $validAt): void
    {
        $config = $this->biConfig();

        $value = $config->vtPrecision === 'datetime'
            ? $validAt->format('Y-m-d H:i:s')
            : $validAt->format('Y-m-d').' 00:00:00';

        $this->query
            ->where($this->table.'.'.$config->columnValidFrom, '<=', $value)
            ->where($this->table.'.'.$config->columnValidTo, '>=', $value);
    }

    /**
     * Cast the pivot attributes and normalise them to a string-indexed array
     * (the framework cast returns an untyped `array`).
     *
     * @param  array<array-key, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function castPivotAttributes(array $attributes): array
    {
        $cast = $this->castAttributes($attributes);

        $result = [];

        foreach ($cast as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
