<?php

namespace Guggach\LaravelDbTemporal\Eloquent;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model&\Guggach\LaravelDbTemporal\Eloquent\BiTemporalModel
 */
class BiTemporalScope implements Scope
{
    /**
     * @var array<int, string>
     */
    protected array $extensions = [
        'CurrentVersion',
        'AllVersions',
        'FirstVersion',
        'LatestVersion',
        'VersionAsOf',
        'ValidAsOf',
        'AsOf',
        'VersionsInValidRange',
        'VersionsTouchingValidRange',
        'DeletedSince',
    ];

    /**
     * @param  Builder<Model>  $builder
     * @param  Model&BiTemporalModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $now = Carbon::now();
        $vtNow = $model->getVtPrecision() === 'datetime'
            ? $now->format('Y-m-d H:i:s')
            : $now->format('Y-m-d').' 00:00:00';

        $builder->where($model->getColumnKnownTo(), $model->getMaxTimestamp())
            ->where($model->getColumnValidFrom(), '<=', $vtNow)
            ->where($model->getColumnValidTo(), '>=', $vtNow);
    }

    /**
     * @param  Builder<TModel>  $builder
     */
    public function extend(Builder $builder): void
    {
        foreach ($this->extensions as $extension) {
            $this->{"add{$extension}"}($builder);
        }
    }

    /**
     * @param  Builder<TModel>  $builder
     */
    protected function addCurrentVersion(Builder $builder): void
    {
        $scope = $this;
        $builder->macro('currentVersion', function (Builder $builder) use ($scope) {
            return $scope->currentVersion($builder);
        });
    }

    /**
     * @param  Builder<TModel>  $builder
     */
    protected function addAllVersions(Builder $builder): void
    {
        $scope = $this;
        $builder->macro('allVersions', function (Builder $builder) use ($scope) {
            return $scope->allVersions($builder);
        });
    }

    /**
     * @param  Builder<TModel>  $builder
     */
    protected function addFirstVersion(Builder $builder): void
    {
        $scope = $this;
        $builder->macro('firstVersion', function (Builder $builder) use ($scope) {
            return $scope->firstVersion($builder);
        });
    }

    /**
     * @param  Builder<TModel>  $builder
     */
    protected function addLatestVersion(Builder $builder): void
    {
        $scope = $this;
        $builder->macro('latestVersion', function (Builder $builder) use ($scope) {
            return $scope->latestVersion($builder);
        });
    }

    /**
     * @param  Builder<TModel>  $builder
     */
    protected function addVersionAsOf(Builder $builder): void
    {
        $scope = $this;
        $builder->macro('versionAsOf', function (Builder $builder, Carbon|string|null $datetime = null) use ($scope) {
            return $scope->versionAsOf($builder, $datetime);
        });
    }

    /**
     * @param  Builder<TModel>  $builder
     */
    protected function addValidAsOf(Builder $builder): void
    {
        $scope = $this;
        $builder->macro('validAsOf', function (Builder $builder, Carbon|string|null $datetime = null) use ($scope) {
            return $scope->validAsOf($builder, $datetime);
        });
    }

    /**
     * @param  Builder<TModel>  $builder
     */
    protected function addAsOf(Builder $builder): void
    {
        $scope = $this;
        $builder->macro('asOf', function (Builder $builder, Carbon|string|null $vtDatetime = null, Carbon|string|null $ttDatetime = null) use ($scope) {
            return $scope->asOf($builder, $vtDatetime, $ttDatetime);
        });
    }

    /**
     * @param  Builder<TModel>  $builder
     */
    protected function addVersionsInValidRange(Builder $builder): void
    {
        $scope = $this;
        $builder->macro('versionsInValidRange', function (Builder $builder, Carbon|string|null $from = null, Carbon|string|null $to = null) use ($scope) {
            return $scope->versionsInValidRange($builder, $from, $to);
        });
    }

    /**
     * @param  Builder<TModel>  $builder
     */
    protected function addVersionsTouchingValidRange(Builder $builder): void
    {
        $scope = $this;
        $builder->macro('versionsTouchingValidRange', function (Builder $builder, Carbon|string|null $from = null, Carbon|string|null $to = null) use ($scope) {
            return $scope->versionsTouchingValidRange($builder, $from, $to);
        });
    }

    /**
     * @param  Builder<TModel>  $builder
     */
    protected function addDeletedSince(Builder $builder): void
    {
        $scope = $this;
        $builder->macro('deletedSince', function (Builder $builder, Carbon|string|null $datetime = null) use ($scope) {
            return $scope->deletedSince($builder, $datetime);
        });
    }

    // -------------------------------------------------------------------------
    // Scope implementations
    // -------------------------------------------------------------------------

    /**
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public function currentVersion(Builder $builder): Builder
    {
        $model = $builder->getModel();

        return $builder->withoutGlobalScope($this)
            ->where($model->getColumnKnownTo(), $model->getMaxTimestamp());
    }

    /**
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public function allVersions(Builder $builder): Builder
    {
        return $builder->withoutGlobalScope($this);
    }

    /**
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public function firstVersion(Builder $builder): Builder
    {
        $model = $builder->getModel();

        return $builder->withoutGlobalScope($this)
            ->oldest($model->getColumnKnownFrom());
    }

    /**
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public function latestVersion(Builder $builder): Builder
    {
        $model = $builder->getModel();

        return $builder->withoutGlobalScope($this)
            ->latest($model->getColumnKnownFrom());
    }

    /**
     * All VT-periods known at a given TT point.
     *
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public function versionAsOf(Builder $builder, Carbon|string|null $datetime = null): Builder
    {
        $datetime = $this->resolveCarbon($datetime);
        $model = $builder->getModel();

        return $builder->withoutGlobalScope($this)
            ->where($model->getColumnKnownFrom(), '<=', $datetime)
            ->where($model->getColumnKnownTo(), '>=', $datetime);
    }

    /**
     * Currently known records covering a given VT point.
     *
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public function validAsOf(Builder $builder, Carbon|string|null $datetime = null): Builder
    {
        $carbon = $this->resolveCarbon($datetime);
        $model = $builder->getModel();
        $vt = $carbon !== null ? $this->formatVt($carbon, $model) : null;

        return $builder->withoutGlobalScope($this)
            ->where($model->getColumnKnownTo(), $model->getMaxTimestamp())
            ->when($vt !== null, fn ($q) => $q
                ->where($model->getColumnValidFrom(), '<=', $vt)
                ->where($model->getColumnValidTo(), '>=', $vt));
    }

    /**
     * Bi-temporal point query: find the record covering both a TT and VT point.
     * TT defaults to now() — navigate the business timeline with today's knowledge.
     *
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public function asOf(Builder $builder, Carbon|string|null $vtDatetime = null, Carbon|string|null $ttDatetime = null): Builder
    {
        $vtCarbon = $this->resolveCarbon($vtDatetime);
        $tt = $this->resolveCarbon($ttDatetime) ?? Carbon::now();
        $model = $builder->getModel();
        $vt = $vtCarbon !== null ? $this->formatVt($vtCarbon, $model) : null;

        $builder->withoutGlobalScope($this)
            ->where($model->getColumnKnownFrom(), '<=', $tt)
            ->where($model->getColumnKnownTo(), '>=', $tt);

        if ($vt !== null) {
            $builder->where($model->getColumnValidFrom(), '<=', $vt)
                ->where($model->getColumnValidTo(), '>=', $vt);
        }

        return $builder;
    }

    /**
     * Currently known records whose VT range is fully within [from, to].
     *
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public function versionsInValidRange(Builder $builder, Carbon|string|null $from = null, Carbon|string|null $to = null): Builder
    {
        $model = $builder->getModel();

        $builder->withoutGlobalScope($this)
            ->where($model->getColumnKnownTo(), $model->getMaxTimestamp());

        if ($from !== null) {
            $builder->where($model->getColumnValidFrom(), '>=', $this->formatVt($this->resolveCarbon($from), $model));
        }

        if ($to !== null) {
            $builder->where($model->getColumnValidTo(), '<=', $this->formatVt($this->resolveCarbon($to), $model));
        }

        return $builder;
    }

    /**
     * Currently known records whose VT range overlaps [from, to].
     *
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public function versionsTouchingValidRange(Builder $builder, Carbon|string|null $from = null, Carbon|string|null $to = null): Builder
    {
        $model = $builder->getModel();

        $builder->withoutGlobalScope($this)
            ->where($model->getColumnKnownTo(), $model->getMaxTimestamp());

        if ($from !== null) {
            $builder->where($model->getColumnValidTo(), '>=', $this->formatVt($this->resolveCarbon($from), $model));
        }

        if ($to !== null) {
            $builder->where($model->getColumnValidFrom(), '<=', $this->formatVt($this->resolveCarbon($to), $model));
        }

        return $builder;
    }

    /**
     * Entities with no remaining TT-open record, optionally filtered by when they were deleted.
     *
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public function deletedSince(Builder $builder, Carbon|string|null $datetime = null): Builder
    {
        $datetime = $this->resolveCarbon($datetime);
        $model = $builder->getModel();

        $builder->withoutGlobalScope($this)
            ->whereNotExists(function (QueryBuilder $query) use ($model) {
                $query->selectRaw('1')
                    ->from($model->getTable(), 'sub')
                    ->whereColumn('sub.'.$model->getKeyName(), $model->getTable().'.'.$model->getKeyName())
                    ->where('sub.'.$model->getColumnKnownTo(), $model->getMaxTimestamp())
                    ->limit(1);
            })
            ->when($datetime, fn ($q) => $q->where($model->getColumnKnownTo(), '>=', $datetime))
            ->latest($model->getColumnKnownTo());

        return $builder;
    }

    private function resolveCarbon(Carbon|string|null $value): ?Carbon
    {
        if (is_null($value)) {
            return null;
        }

        return $value instanceof Carbon ? $value : new Carbon($value);
    }

    /** Format a Carbon value to match the model's VT column storage format. */
    private function formatVt(Carbon $value, BiTemporalModel $model): string
    {
        return $model->getVtPrecision() === 'datetime'
            ? $value->format('Y-m-d H:i:s')
            : $value->format('Y-m-d').' 00:00:00';
    }
}
