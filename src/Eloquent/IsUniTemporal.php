<?php

namespace Guggach\LaravelDbTemporal\Eloquent;

use Guggach\LaravelDbTemporal\Connections\TemporalConnection;
use Guggach\LaravelDbTemporal\Database\Query\UniTemporalBuilder;
use Illuminate\Database\Eloquent\Builder;

trait IsUniTemporal
{
    public static function bootIsUniTemporal(): void
    {
        static::addGlobalScope(new UniTemporalScope);
    }

    public function initializeIsUniTemporal(): void
    {
        if (! isset($this->casts[$this->getColumnTrxFrom()])) {
            $this->casts[$this->getColumnTrxFrom()] = 'datetime';
        }
        if (! isset($this->casts[$this->getColumnTrxTo()])) {
            $this->casts[$this->getColumnTrxTo()] = 'datetime';
        }
    }

    public function getMaxTimestamp(): string
    {
        $fromConnection = $this->getTemporalConfigValue('max_timestamp');

        return defined('static::MAX_TIMESTAMP')
            ? static::MAX_TIMESTAMP
            : ($fromConnection ?? config('db-temporal.defaults.maxTimestamp') ?? '9999-12-31 23:59:59');
    }

    public function getColumnTrxFrom(): string
    {
        $fromConnection = $this->getTemporalConfigValue('column_from');

        return defined('static::COLUMN_TRX_DATE_FROM')
            ? static::COLUMN_TRX_DATE_FROM
            : ($fromConnection ?? config('db-temporal.defaults.columnTrxDateFrom') ?? 'trx_date_from');
    }

    public function getColumnTrxTo(): string
    {
        $fromConnection = $this->getTemporalConfigValue('column_to');

        return defined('static::COLUMN_TRX_DATE_TO')
            ? static::COLUMN_TRX_DATE_TO
            : ($fromConnection ?? config('db-temporal.defaults.columnTrxDateTo') ?? 'trx_date_to');
    }

    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        $query = new UniTemporalBuilder(
            $connection,
            $connection->getQueryGrammar(),
            $connection->getPostProcessor()
        );

        $query->setTemporalColumnNames(
            $this->getColumnTrxFrom(),
            $this->getColumnTrxTo(),
            $this->getMaxTimestamp(),
            true
        );

        return $query;
    }

    protected function performInsert(Builder $query): bool
    {
        $this->setTransactionTimestamps();

        return parent::performInsert($query);
    }

    private function setTransactionTimestamps(): void
    {
        $this->setAttribute($this->getColumnTrxFrom(), $this->freshTimestamp());
        $this->setAttribute($this->getColumnTrxTo(), $this->getMaxTimestamp());
    }

    private function getTemporalConfigValue(string $key): ?string
    {
        $connection = $this->getConnection();

        if (! $connection instanceof TemporalConnection) {
            return null;
        }

        $config = $connection->getUniTemporalTableConfig($this->getTable());

        return $config[$key] ?? null;
    }
}
