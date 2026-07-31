<?php

namespace Guggach\LaravelDbTemporal\Eloquent;

use Guggach\LaravelDbTemporal\Configuration\BiTemporalConfig;
use Guggach\LaravelDbTemporal\Connections\TemporalConnection;
use Guggach\LaravelDbTemporal\Database\Query\BiTemporalBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements BiTemporalModel
 */
trait IsBiTemporal
{
    public static function bootIsBiTemporal(): void
    {
        static::addGlobalScope(new BiTemporalScope);
    }

    public function initializeIsBiTemporal(): void
    {
        $vtCast = $this->getVtPrecision() === 'datetime' ? 'datetime' : 'date';

        if (! isset($this->casts[$this->getColumnValidFrom()])) {
            $this->casts[$this->getColumnValidFrom()] = $vtCast;
        }
        if (! isset($this->casts[$this->getColumnValidTo()])) {
            $this->casts[$this->getColumnValidTo()] = $vtCast;
        }
        if (! isset($this->casts[$this->getColumnKnownFrom()])) {
            $this->casts[$this->getColumnKnownFrom()] = 'datetime';
        }
        if (! isset($this->casts[$this->getColumnKnownTo()])) {
            $this->casts[$this->getColumnKnownTo()] = 'datetime';
        }
    }

    public function getColumnValidFrom(): string
    {
        return $this->resolveTemporalConfig()->columnValidFrom;
    }

    public function getColumnValidTo(): string
    {
        return $this->resolveTemporalConfig()->columnValidTo;
    }

    public function getColumnKnownFrom(): string
    {
        return $this->resolveTemporalConfig()->columnKnownFrom;
    }

    public function getColumnKnownTo(): string
    {
        return $this->resolveTemporalConfig()->columnKnownTo;
    }

    /** @return 'day'|'datetime' */
    public function getVtPrecision(): string
    {
        $precision = $this->resolveTemporalConfig()->vtPrecision;

        return $precision === 'datetime' ? 'datetime' : 'day';
    }

    public function getVtMaxSentinel(): string
    {
        return $this->resolveTemporalConfig()->vtMaxSentinel();
    }

    public function getMaxTimestamp(): string
    {
        return $this->resolveTemporalConfig()->maxTimestamp;
    }

    protected function newBaseQueryBuilder(): BiTemporalBuilder
    {
        $connection = $this->getConnection();

        $query = new BiTemporalBuilder(
            $connection,
            $connection->getQueryGrammar(),
            $connection->getPostProcessor()
        );

        $query->setTemporalConfig($this->resolveTemporalConfig(), calledByEloquent: true);

        return $query;
    }

    protected function performInsert(Builder $query): bool
    {
        $this->setBiTemporalTimestamps();

        $keyName = $this->getKeyName();
        if (! $this->getIncrementing() && ! $this->usesUniqueIds() && is_null($this->getAttribute($keyName))) {
            $max = $query->max($keyName);
            /** @var int|float|string|null $max */
            $nextId = (is_numeric($max) ? (int) $max : 0) + 1;
            $this->setAttribute($keyName, $nextId);
        }

        return parent::performInsert($query);
    }

    private function setBiTemporalTimestamps(): void
    {
        $now = $this->freshTimestamp();
        $vtFormat = $this->getVtPrecision() === 'datetime' ? 'Y-m-d H:i:s' : 'Y-m-d';

        if (! $this->getAttribute($this->getColumnValidFrom())) {
            $this->setAttribute($this->getColumnValidFrom(), $now->format($vtFormat));
        }
        if (! $this->getAttribute($this->getColumnValidTo())) {
            $this->setAttribute($this->getColumnValidTo(), $this->getVtMaxSentinel());
        }

        $this->setAttribute($this->getColumnKnownFrom(), $now);
        $this->setAttribute($this->getColumnKnownTo(), $this->getMaxTimestamp());
    }

    private function resolveTemporalConfig(): BiTemporalConfig
    {
        $base = $this->loadBaseTemporalConfig();

        return $base->withOverrides(
            columnValidFrom: $this->constantIfDefined('COLUMN_VALID_FROM'),
            columnValidTo: $this->constantIfDefined('COLUMN_VALID_TO'),
            columnKnownFrom: $this->constantIfDefined('COLUMN_KNOWN_FROM'),
            columnKnownTo: $this->constantIfDefined('COLUMN_KNOWN_TO'),
            vtPrecision: $this->constantIfDefined('VT_PRECISION'),
            maxDate: $this->constantIfDefined('MAX_DATE'),
            maxTimestamp: $this->constantIfDefined('MAX_TIMESTAMP'),
        );
    }

    private function loadBaseTemporalConfig(): BiTemporalConfig
    {
        $connection = $this->getConnection();

        if (! $connection instanceof TemporalConnection) {
            return BiTemporalConfig::fromArray(null);
        }

        $tableConfig = $connection->getBiTemporalTableConfig($this->getTable());

        return $tableConfig ?? $connection->getBiTemporalDefaults();
    }

    private function constantIfDefined(string $name): ?string
    {
        if (! defined('static::'.$name)) {
            return null;
        }
        $value = constant('static::'.$name);

        return is_string($value) ? $value : null;
    }
}
