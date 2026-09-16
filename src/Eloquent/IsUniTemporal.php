<?php

namespace Guggach\LaravelDbTemporal\Eloquent;

use Guggach\LaravelDbTemporal\Configuration\TemporalConfig;
use Guggach\LaravelDbTemporal\Connections\TemporalConnection;
use Guggach\LaravelDbTemporal\Database\Query\UniTemporalBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements UniTemporalModel
 */
trait IsUniTemporal
{
    public static function bootIsUniTemporal(): void
    {
        static::addGlobalScope(new UniTemporalScope);
    }

    public function initializeIsUniTemporal(): void
    {
        // Format-spezifische Casts nur für die Transaktionszeit: ein
        // model-weites $dateFormat würde auch fachliche Datumsattribute
        // mit Mikrosekunden schreiben und Equality-Vergleiche brechen.
        if (! isset($this->casts[$this->getColumnTrxFrom()])) {
            $this->casts[$this->getColumnTrxFrom()] = 'datetime:Y-m-d H:i:s.u';
        }
        if (! isset($this->casts[$this->getColumnTrxTo()])) {
            $this->casts[$this->getColumnTrxTo()] = 'datetime:Y-m-d H:i:s.u';
        }
    }

    public function getMaxTimestamp(): string
    {
        return $this->resolveTemporalConfig()->maxTimestamp;
    }

    public function getColumnTrxFrom(): string
    {
        return $this->resolveTemporalConfig()->columnFrom;
    }

    public function getColumnTrxTo(): string
    {
        return $this->resolveTemporalConfig()->columnTo;
    }

    protected function newBaseQueryBuilder(): UniTemporalBuilder
    {
        $connection = $this->getConnection();

        $query = new UniTemporalBuilder(
            $connection,
            $connection->getQueryGrammar(),
            $connection->getPostProcessor()
        );

        $query->setTemporalConfig($this->resolveTemporalConfig(), calledByEloquent: true);

        return $query;
    }

    protected function performInsert(Builder $query): bool
    {
        $this->setTransactionTimestamps();

        $keyName = $this->getKeyName();
        if (! $this->getIncrementing() && ! $this->usesUniqueIds() && is_null($this->getAttribute($keyName))) {
            $max = $query->max($keyName);
            /** @var int|float|string|null $max */
            $nextId = (is_numeric($max) ? (int) $max : 0) + 1;
            $this->setAttribute($keyName, $nextId);
        }

        return parent::performInsert($query);
    }

    private function setTransactionTimestamps(): void
    {
        // Mikrosekunden sind Pflicht: zwei Versionen in derselben Sekunde
        // (Batch-Prozesse!) wuerden sonst im PK (id, known_from, known_to)
        // kollidieren. Direkte Array-Writes umgehen setAttribute, das fuer
        // Datums-Casts ueber fromDateTime auf das Sekundenformat des
        // Query-Grammars zurueckfaellt und µs abschneiden wuerde.
        $this->attributes[$this->getColumnTrxFrom()] = $this->freshTimestamp()->format('Y-m-d H:i:s.u');
        $this->attributes[$this->getColumnTrxTo()] = $this->getMaxTimestamp();
    }

    private function resolveTemporalConfig(): TemporalConfig
    {
        $base = $this->loadBaseTemporalConfig();

        return $base->withOverrides(
            columnFrom: $this->constantIfDefined('COLUMN_TRX_DATE_FROM'),
            columnTo: $this->constantIfDefined('COLUMN_TRX_DATE_TO'),
            maxTimestamp: $this->constantIfDefined('MAX_TIMESTAMP'),
        );
    }

    private function loadBaseTemporalConfig(): TemporalConfig
    {
        $connection = $this->getConnection();

        if (! $connection instanceof TemporalConnection) {
            return TemporalConfig::fromArray(null);
        }

        $tableConfig = $connection->getUniTemporalTableConfig($this->getTable());

        return $tableConfig ?? $connection->getUniTemporalDefaults();
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
