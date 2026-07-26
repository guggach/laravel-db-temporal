<?php

namespace Guggach\LaravelDbTemporal\Eloquent;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class UniTemporalScope implements Scope
{
    /**
     * All of the extensions to be added to the builder.
     *
     * @var array
     */
    protected $extensions = ['CurrentVersion', 'AllVersions', 'FirstVersion', 'VersionAsOf', 'VersionsInRange', 'VersionsTouchedRange', 'LatestVersion'];

    /**
     * Apply the scope to a given Eloquent query builder.
     * Only shows the currently active revision
     *
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        $builder->where($model->getColumnTrxTo(), $model->getMaxTimestamp());
    }

    public function extend(Builder $builder)
    {
        foreach ($this->extensions as $extension) {
            $this->{"add{$extension}"}($builder);
        }

        // $builder->onDelete(function (Builder $builder) {
        // 	$model = $builder->getModel();
        // 	$column = $model->getTemporalEndColumn();
        // 	return $builder->update([
        // 		$column => $model->freshTimestampString(),
        // 	]);
        // });
    }

    /**
     * Retrive the current active transaction version. This is default by global scope. Without scope use this function.
     */
    protected function addCurrentVersion(Builder $builder)
    {
        $builder->macro('currentVersion', function (Builder $builder) {
            $model = $builder->getModel();

            $builder->withoutGlobalScope($this)
                ->where($model->getColumnTrxTo(), $model->getMaxTimestamp());

            return $builder;
        });
    }

    /**
     * The allVersions builder method will remove the constraint that normally causes just the currently active versions to be returned.
     * Use this scope only with ->get() function.
     */
    protected function addAllVersions(Builder $builder)
    {
        $builder->macro('allVersions', function (Builder $builder) {
            return $builder->withoutGlobalScope($this);
        });
    }

    /**
     * The firstVersions builder method will constrain the first entry. Use this scope only with ->first() function
     */
    protected function addFirstVersion(Builder $builder)
    {
        $builder->macro('firstVersion', function (Builder $builder) {
            $model = $builder->getModel();

            $builder->withoutGlobalScope($this)
                ->oldest($model->getColumnTrxTo());

            return $builder;
        });
    }

    /**
     * The versionAsOf builder method will retrive the version of a certain date independent of current or history.
     * Use ->first() or ->get() function
     */
    protected function addVersionAsOf(Builder $builder)
    {
        $builder->macro('versionAsOf', function (Builder $builder, Carbon|string|null $datetime = null) {
            if (! $datetime instanceof Carbon) {
                $datetime = new Carbon($datetime);
            }

            $model = $builder->getModel();

            $builder->withoutGlobalScope($this)
                ->where($model->getColumnTrxFrom(), '<=', $datetime)
                ->where($model->getColumnTrxTo(), '>=', $datetime);

            return $builder;
        });
    }

    /**
     * The versionsInRange builder method will retrive all transactions which are or were fully known in the given date range
     * You can specify null as the $from or $to date to get all revisions in that direction of time.
     * Use ->get() function.
     */
    protected function addVersionsInRange(Builder $builder)
    {
        $builder->macro('versionsInRange', function (Builder $builder, Carbon|string|null $from = null, Carbon|string|null $to = null) {
            if (! $from instanceof Carbon) {
                $from = new Carbon($from);
            }
            if (! $to instanceof Carbon) {
                $to = new Carbon($to);
            }

            $model = $builder->getModel();

            $builder->withoutGlobalScope($this);
            $builder->where($model->getColumnTrxFrom(), '>=', $from);
            $builder->where($model->getColumnTrxTo(), '<=', $to);

            return $builder;
        });
    }

    /**
     * The versionsTouchedRange builder method will retrive all transactions which are or were fully or partially known in the given date range
     * You can specify null as the $from or $to date to get all revisions in that direction of time.
     * Use ->get() function.
     */
    protected function addVersionsTouchedRange(Builder $builder)
    {
        $builder->macro('versionsTouchedRange', function (Builder $builder, Carbon|string|null $from = null, Carbon|string|null $to = null) {
            if (! $from instanceof Carbon) {
                $from = new Carbon($from);
            }
            if (! $to instanceof Carbon) {
                $to = new Carbon($to);
            }

            $model = $builder->getModel();

            $builder->withoutGlobalScope($this);

            $builder->where($model->getColumnTrxTo(), '>=', $from);
            $builder->where($model->getColumnTrxFrom(), '<=', $to);

            return $builder;
        });
    }

    /**
     * The latestVersion builder method will constrain latest Entry. This could could be the current or a deleted records
     * Use this scope only with ->first() function
     */
    protected function addLatestVersion(Builder $builder)
    {
        $builder->macro('latestVersion', function (Builder $builder) {
            $model = $builder->getModel();

            $builder->withoutGlobalScope($this)
                ->latest($model->getColumnTrxTo());

            return $builder;
        });
    }

    /**
     * Retrive the latest transaction without a following current active version. Finally this tuple has been deleted (not softdeleted).
     * Use this scope only with ->first() function
     *
     * NOT working complex query
     */
    // protected function addDeletedVersion(Builder $builder)
    // {
    // 	$builder->macro('deletedVersion', function (Builder $builder)
    // 	{
    // 		$model = $builder->getModel();

    // 		$builder->withoutGlobalScope($this)
    // 			->where($model->getColumnTrxTo(), '<' , $model->getMaxTimestamp())
    //             ->latest($model->getColumnTrxTo());

    // 		return $builder;
    // 	});
    // }
}
