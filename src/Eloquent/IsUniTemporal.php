<?php

namespace Guggach\LaravelDbTemporal\Eloquent;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use phpDocumentor\Reflection\Types\Void_;
use Guggach\LaravelDbTemporal\Database\Query\UniTemporalBuilder;

trait IsUniTemporal
{

    /**
     * Overwriteable variable which are used in Unitemporal
     * SYS_FROM could be used as synomym for database believed_from / _to or
     * often as system_from / to or known_from / known_until
     */

    // protected $maxSysTimestamp = config('db-temporal.default.maxTimestamp');
    // protected $attributeSysFrom = config('db-temporal.default.attributeSysFrom');
    // protected $attributeSysTo = config('db-temporal.default.attributeSysTo');


    /**
     * Boot the Uni Temporal trait for a model.
     *
     * @return void
     */
    public static function bootIsUniTemporal()
    {
        static::addGlobalScope(new UniTemporalScope);
    }

    /**
     * Initialize the Uni Temporal trait for an instance.
     *
     * @return void
     */
    public function initializeIsUniTemporal()
    {
        // $this->columnTrxFrom = $this->columnTrxFrom ?? config('db-temporal.defaults.columnTrxFrom') ?? 'trx_date_from';
        // $this->columnTrxTo = $this->columnTrxTo ?? config('db-temporal.defaults.columnTrxTo') ?? 'trx_date_to';
        // $this->maxTimestamp = $this->maxTimestamp ?? config('db-temporal.defaults.maxTimestamp') ?? '9999-12-31 23:59:59';

        // if (! isset($this->casts[$this->getDeletedAtColumn()])) {
        //     $this->casts[$this->getDeletedAtColumn()] = 'datetime';
        // }
        if (! isset($this->casts[$this->getColumnTrxFrom()])) {
            $this->casts[$this->getColumnTrxFrom()] = 'datetime';
        }
        if (! isset($this->casts[$this->getColumnTrxTo()])) {
            $this->casts[$this->getColumnTrxTo()] = 'datetime';
        }

    }


    public function getMaxTimestamp(){
        return defined('static::MAX_TIMESTAMP') ? static::MAX_TIMESTAMP : config('db-temporal.defaults.maxTimestamp') ?? '9999-12-31 23:59:59';
    }

    public function getColumnTrxFrom(){
        return defined('static::COLUMN_TRX_DATE_FROM') ? static::COLUMN_TRX_DATE_FROM : config('db-temporal.defaults.columnTrxDateFrom') ?? 'trx_date_from';

    }

    public function getColumnTrxTo(){
        return defined('static::COLUMN_TRX_DATE_TO') ? static::COLUMN_TRX_DATE_TO : config('db-temporal.defaults.columnTrxDateTo') ?? 'trx_date_to';
    }

    /**
     * Get a new modified Database Builder.
     *
     * @return \Guggach\LaravelDbTemporal\Database\Query\UniTemporalBuilder
     */
    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        $query = new UniTemporalBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
        $query->setTemporalColumnNames($this->getColumnTrxFrom(), $this->getColumnTrxTo(),
            $this->getMaxTimestamp(), true);
        return $query;
    }

    /**
     * Perform a model insert operation with database refresh.
     * Unitemporal date attributes are not updated if user deliver values.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return bool
     */
    protected function performInsert(Builder $query) : bool
    {
        $this->setTransactionTimestamps();
        Parent::performInsert($query);
        return true;
    }

    private function setTransactionTimestamps() : Void
    {
        $this->setAttribute($this->getColumnTrxFrom(), $this->freshTimestamp());
        $this->setAttribute($this->getColumnTrxTo(), $this->getMaxTimestamp());
    }


}
