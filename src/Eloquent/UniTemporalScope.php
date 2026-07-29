<?php

namespace Guggach\LaravelDbTemporal\Eloquent;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class UniTemporalScope implements Scope
{
    protected $extensions = [
        'CurrentVersion',
        'AllVersions',
        'FirstVersion',
        'VersionAsOf',
        'VersionsInRange',
        'VersionsTouchedRange',
        'LatestVersion',
        'DeletedSince',
    ];

    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->getColumnTrxTo(), $model->getMaxTimestamp());
    }

    public function extend(Builder $builder): void
    {
        foreach ($this->extensions as $extension) {
            $this->{"add{$extension}"}($builder);
        }
    }

    protected function addCurrentVersion(Builder $builder): void
    {
        $builder->macro('currentVersion', function (Builder $builder) {
            $model = $builder->getModel();

            $builder->withoutGlobalScope($this)
                ->where($model->getColumnTrxTo(), $model->getMaxTimestamp());

            return $builder;
        });
    }

    protected function addAllVersions(Builder $builder): void
    {
        $builder->macro('allVersions', function (Builder $builder) {
            return $builder->withoutGlobalScope($this);
        });
    }

    protected function addFirstVersion(Builder $builder): void
    {
        $builder->macro('firstVersion', function (Builder $builder) {
            $model = $builder->getModel();

            return $builder->withoutGlobalScope($this)
                ->oldest($model->getColumnTrxTo());
        });
    }

    protected function addVersionAsOf(Builder $builder): void
    {
        $builder->macro('versionAsOf', function (Builder $builder, Carbon|string|null $datetime = null) {
            $datetime = $this->resolveCarbon($datetime);

            $model = $builder->getModel();

            return $builder->withoutGlobalScope($this)
                ->where($model->getColumnTrxFrom(), '<=', $datetime)
                ->where($model->getColumnTrxTo(), '>=', $datetime);
        });
    }

    protected function addVersionsInRange(Builder $builder): void
    {
        $builder->macro('versionsInRange', function (Builder $builder, Carbon|string|null $from = null, Carbon|string|null $to = null) {
            $model = $builder->getModel();

            $builder->withoutGlobalScope($this);

            if (! is_null($from)) {
                $builder->where($model->getColumnTrxFrom(), '>=', $this->resolveCarbon($from));
            }

            if (! is_null($to)) {
                $builder->where($model->getColumnTrxTo(), '<=', $this->resolveCarbon($to));
            }

            return $builder;
        });
    }

    protected function addVersionsTouchedRange(Builder $builder): void
    {
        $builder->macro('versionsTouchedRange', function (Builder $builder, Carbon|string|null $from = null, Carbon|string|null $to = null) {
            $model = $builder->getModel();

            $builder->withoutGlobalScope($this);

            if (! is_null($from)) {
                $builder->where($model->getColumnTrxTo(), '>=', $this->resolveCarbon($from));
            }

            if (! is_null($to)) {
                $builder->where($model->getColumnTrxFrom(), '<=', $this->resolveCarbon($to));
            }

            return $builder;
        });
    }

    protected function addLatestVersion(Builder $builder): void
    {
        $builder->macro('latestVersion', function (Builder $builder) {
            $model = $builder->getModel();

            return $builder->withoutGlobalScope($this)
                ->latest($model->getColumnTrxTo());
        });
    }

    protected function addDeletedSince(Builder $builder): void
    {
        $builder->macro('deletedSince', function (Builder $builder, Carbon|string|null $datetime = null) {
            $datetime = $this->resolveCarbon($datetime);

            $model = $builder->getModel();

            return $builder->withoutGlobalScope($this)
                ->whereNotExists(function ($query) use ($model) {
                    $query->selectRaw('1')
                        ->from($model->getTable(), 'sub')
                        ->whereColumn('sub.' . $model->getKeyName(), $model->getTable() . '.' . $model->getKeyName())
                        ->where('sub.' . $model->getColumnTrxTo(), $model->getMaxTimestamp())
                        ->limit(1);
                })
                ->when($datetime, fn ($q) => $q->where($model->getColumnTrxTo(), '>=', $datetime))
                ->latest($model->getColumnTrxTo());
        });
    }

    private function resolveCarbon(Carbon|string|null $value): ?Carbon
    {
        if (is_null($value)) {
            return null;
        }

        return $value instanceof Carbon ? $value : new Carbon($value);
    }
}
