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
 * Temporale Pivot-Semantik für eine `BelongsToMany`/`MorphToMany`-Relation.
 *
 * Die Pivot-Tabelle bleibt die „normale" Laravel-Pivot-Tabelle, wird aber
 * versioniert: Reads liefern nur den aktuellen Zustand, `attach` fügt eine
 * neue Version ein, `detach`/`updateExistingPivot` schliessen die aktuelle
 * Version (und setzen SoftDelete, wenn vorhanden). Nicht-temporale Pivot-
 * Tabellen delegieren unverändert an die Basis-Relation.
 *
 * Erkennung (in dieser Reihenfolge): Marker-Trait am `using`-Model, dann
 * Connection-Config (uni-/bi-temporal.tables), dann Schema (`known_from`/
 * `known_to`, optional `valid_from`/`valid_to`).
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
     * Basis-Query (Join + Fremdschlüssel) ohne Default-Current-Filter,
     * gesichert für as-of.
     *
     * @var EloquentBuilder<*>|null
     */
    protected ?EloquentBuilder $pivotBaseQuery = null;

    /**
     * Spaltenlisting pro Connection|Tabelle (vermeidet wiederholte
     * Schema-Abfragen bei jeder Relationserzeugung).
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
        return $this->pivotUniConfig ?? throw new LogicException('Uni-temporale Pivot-Config nicht aufgelöst.');
    }

    protected function biConfig(): BiTemporalConfig
    {
        return $this->pivotBiConfig ?? throw new LogicException('Bi-temporale Pivot-Config nicht aufgelöst.');
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
     * Die temporalen Spalten zusätzlich zu den konfigurierten Pivot-Spalten
     * selektieren, damit `->pivot` sie exponiert.
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

        // Basis-Query für as-of sichern, bevor die Default-Current-Filter drankommen.
        $this->pivotBaseQuery = clone $this->query;

        $this->applyCurrentPivotConstraints($this->query);

        $this->pivotColumns = array_values(array_unique(
            array_merge($this->pivotColumns, $this->pivotTemporalPivotColumns()),
            SORT_REGULAR,
        ));
    }

    /**
     * `$ids` ist die Related-Seite (Werte des `$relatedPivotKey`, z.B. die IDs
     * der Targets) — die foreign-Seite (`$foreignPivotKey`, z.B. `owner_id`)
     * kommt automatisch aus dem Parent-Model. Eine Composite-Identität
     * `(foreign, related)` wird also über Parent + `$ids` gebildet; weitere
     * Pivot-Spalten (Rolle, primär, …) gehören in `$attributes`.
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
     * `$ids` ist die Related-Seite (Werte des `$relatedPivotKey`); `null`
     * entfernt alle Zuordnungen des Parents. Die foreign-Seite kommt aus dem
     * Parent-Model.
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
            // Bi-temporal: Gültigkeit schliessen (VT), nicht soft-deleten.
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
     * Bi-temporaler As-of-Zugriff: `$validAt` = fachlicher Stichtag,
     * `$knownAt` = Wissensstand (Default jetzt). Ohne `$validAt` wird nur der
     * Transaktionszeitpunkt gefiltert. Nur für bi-temporale Pivots.
     *
     * Achtung: baut die Relation-Query neu auf und übernimmt daher keine
     * zusätzlichen Constraints aus der Relation-Definition.
     */
    public function asOf(Carbon|string|null $validAt = null, Carbon|string|null $knownAt = null): static
    {
        $this->resolvePivotTemporal();

        if ($this->pivotTemporalMode !== 'bi') {
            throw new LogicException('asOf ist nur für bi-temporale Pivot-Tabellen verfügbar.');
        }

        if ($this->pivotBaseQuery !== null) {
            // Nur die Basis-Query (Join + FK) übernehmen, ohne Default-Current-Filter.
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

    /** Gültigkeit zum Stichtag, bekannt jetzt. */
    public function validAsOf(Carbon|string $validAt): static
    {
        return $this->asOf($validAt);
    }

    /** Zustand zum Wissensstand (ohne Gültigkeitsfilter). */
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
     * Pivot-Attribute casten und auf eine string-indizierte Form normalisieren
     * (der Framework-Cast liefert einen untypisierten `array`).
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
