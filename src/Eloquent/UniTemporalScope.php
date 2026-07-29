<?php

namespace Guggach\LaravelDbTemporal\Eloquent;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model&\Guggach\LaravelDbTemporal\Eloquent\UniTemporalModel
 */
class UniTemporalScope implements Scope
{
    /**
     * @var array<int, string>
     */
    protected array $extensions = [
        'CurrentVersion',
        'AllVersions',
        'FirstVersion',
        'VersionAsOf',
        'VersionsInRange',
        'VersionsTouchedRange',
        'LatestVersion',
        'DeletedSince',
    ];

    /**
     * @param  Builder<Model>  $builder
     * @param  Model&UniTemporalModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->getColumnTrxTo(), $model->getMaxTimestamp());
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
    protected function addVersionsInRange(Builder $builder): void
    {
        $scope = $this;
        $builder->macro('versionsInRange', function (Builder $builder, Carbon|string|null $from = null, Carbon|string|null $to = null) use ($scope) {
            return $scope->versionsInRange($builder, $from, $to);
        });
    }

    /**
     * @param  Builder<TModel>  $builder
     */
    protected function addVersionsTouchedRange(Builder $builder): void
    {
        $scope = $this;
        $builder->macro('versionsTouchedRange', function (Builder $builder, Carbon|string|null $from = null, Carbon|string|null $to = null) use ($scope) {
            return $scope->versionsTouchedRange($builder, $from, $to);
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
    protected function addDeletedSince(Builder $builder): void
    {
        $scope = $this;
        $builder->macro('deletedSince', function (Builder $builder, Carbon|string|null $datetime = null) use ($scope) {
            return $scope->deletedSince($builder, $datetime);
        });
    }

    /**
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public function currentVersion(Builder $builder): Builder
    {
        $model = $builder->getModel();

        return $builder->withoutGlobalScope($this)
            ->where($model->getColumnTrxTo(), $model->getMaxTimestamp());
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
            ->oldest($model->getColumnTrxTo());
    }

    /**
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public function versionAsOf(Builder $builder, Carbon|string|null $datetime = null): Builder
    {
        $datetime = $this->resolveCarbon($datetime);

        $model = $builder->getModel();

        return $builder->withoutGlobalScope($this)
            ->where($model->getColumnTrxFrom(), '<=', $datetime)
            ->where($model->getColumnTrxTo(), '>=', $datetime);
    }

    /**
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public function versionsInRange(Builder $builder, Carbon|string|null $from = null, Carbon|string|null $to = null): Builder
    {
        $model = $builder->getModel();

        $builder->withoutGlobalScope($this);

        if (! is_null($from)) {
            $builder->where($model->getColumnTrxFrom(), '>=', $this->resolveCarbon($from));
        }

        if (! is_null($to)) {
            $builder->where($model->getColumnTrxTo(), '<=', $this->resolveCarbon($to));
        }

        return $builder;
    }

    /**
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public function versionsTouchedRange(Builder $builder, Carbon|string|null $from = null, Carbon|string|null $to = null): Builder
    {
        $model = $builder->getModel();

        $builder->withoutGlobalScope($this);

        if (! is_null($from)) {
            $builder->where($model->getColumnTrxTo(), '>=', $this->resolveCarbon($from));
        }

        if (! is_null($to)) {
            $builder->where($model->getColumnTrxFrom(), '<=', $this->resolveCarbon($to));
        }

        return $builder;
    }

    /**
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public function latestVersion(Builder $builder): Builder
    {
        $model = $builder->getModel();

        return $builder->withoutGlobalScope($this)
            ->latest($model->getColumnTrxTo());
    }

    /**
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
                    ->whereColumn('sub.' . $model->getKeyName(), $model->getTable() . '.' . $model->getKeyName())
                    ->where('sub.' . $model->getColumnTrxTo(), $model->getMaxTimestamp())
                    ->limit(1);
            })
            ->when($datetime, fn ($q) => $q->where($model->getColumnTrxTo(), '>=', $datetime))
            ->latest($model->getColumnTrxTo());

        return $builder;
    }

    private function resolveCarbon(Carbon|string|null $value): ?Carbon
    {
        if (is_null($value)) {
            return null;
        }

        return $value instanceof Carbon ? $value : new Carbon($value);
    }
}
