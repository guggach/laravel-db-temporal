<?php

namespace Guggach\LaravelDbTemporal\Database\Query;

use BackedEnum;
use Carbon\Carbon;
use DateTime;
use DateTimeInterface;
use Guggach\LaravelDbTemporal\Configuration\TemporalConfig;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Support\Arr;
use Stringable;

/**
 * @phpstan-type RecordValue scalar|Stringable|DateTimeInterface|BackedEnum|array<scalar|Stringable|DateTimeInterface|BackedEnum|null>|null
 * @phpstan-type Record array<string, RecordValue>
 * @phpstan-type Records array<int|string, Record>
 */
class UniTemporalBuilder extends Builder
{
    private string $columnTrxDateFrom;

    private string $columnTrxDateTo;

    private string $maxTimestamp;

    private bool $calledByEloquent = false;

    private bool $skipVersioning = false;

    public function skipVersioning(): void
    {
        $this->skipVersioning = true;
    }

    public function resumeVersioning(): void
    {
        $this->skipVersioning = false;
    }

    public function __construct(
        ConnectionInterface $connection,
        ?Grammar $grammar = null,
        ?Processor $processor = null,
    ) {
        $this->columnTrxDateFrom = 'known_from';
        $this->columnTrxDateTo = 'known_to';
        $this->maxTimestamp = '9999-12-31 23:59:59';

        parent::__construct($connection, $grammar, $processor);
    }

    public function setTemporalColumnNames(
        ?string $columnTrxDateFrom = null,
        ?string $columnTrxDateTo = null,
        ?string $maxTimestamp = null,
        bool $calledByEloquent = false,
    ): void {
        $this->columnTrxDateFrom = $columnTrxDateFrom ?? $this->columnTrxDateFrom;
        $this->columnTrxDateTo = $columnTrxDateTo ?? $this->columnTrxDateTo;
        $this->maxTimestamp = $maxTimestamp ?? $this->maxTimestamp;
        $this->calledByEloquent = $calledByEloquent;
    }

    public function setTemporalConfig(TemporalConfig $config, bool $calledByEloquent = false): void
    {
        $this->setTemporalColumnNames($config->columnFrom, $config->columnTo, $config->maxTimestamp, $calledByEloquent);
    }

    /**
     * @param  Record|Records  $values
     */
    public function insert(array $values): bool
    {
        if ($values === []) {
            return true;
        }

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        /** @var Records $records */
        $records = [];
        foreach ($values as $key => $record) {
            if (is_array($record)) {
                /** @var Record $record */
                ksort($record);
                /** @var int|string $key */
                $records[$key] = $record;
            }
        }

        $values = $this->setTransactionTimestamp($records);

        $this->applyBeforeQueryCallbacks();

        return $this->connection->insert(
            $this->grammar->compileInsert($this, $values),
            $this->cleanBindings(Arr::flatten($values, 1)),
        );
    }

    /**
     * @param  Record  $values
     * @param  mixed  $sequence
     */
    public function insertGetId(array $values, $sequence = null): int
    {
        $this->applyBeforeQueryCallbacks();

        $sequence = is_string($sequence) ? $sequence : 'id';

        $max = $this->max($sequence);
        $newId = (is_numeric($max) ? (int) $max : 0) + 1;

        $values[$sequence] = $newId;

        if (! array_key_exists($this->columnTrxDateFrom, $values)) {
            $values[$this->columnTrxDateFrom] = (new DateTime)->format('Y-m-d H:i:s.u');
        }

        if (! array_key_exists($this->columnTrxDateTo, $values)) {
            $values[$this->columnTrxDateTo] = $this->maxTimestamp;
        }

        $bindings = $this->cleanBindings(Arr::flatten([$values], 1));

        return $this->connection->insert(
            $this->grammar->compileInsert($this, [$values]),
            $bindings,
        ) ? $newId : 0;
    }

    /**
     * @param  Record  $values
     */
    public function update(array $values): int
    {
        $this->applyBeforeQueryCallbacks();

        if ($this->skipVersioning) {
            return parent::update($values);
        }

        $query = new Builder($this->connection, $this->grammar, $this->processor);
        $query->from($this->from);
        $query->wheres = $this->wheres;
        $query->bindings = $this->bindings;
        $query->where($this->columnTrxDateTo, $this->maxTimestamp);
        /** @var array<int, object> $oldRecs */
        $oldRecs = $query->get();

        $updateTime = new Carbon;
        $count = 0;

        foreach ($oldRecs as $oldRec) {
            $oldRecord = (array) $oldRec;
            /** @var array<string, RecordValue> $oldRecord */
            $oldRecord = $oldRecord;

            foreach ($values as $key => $value) {
                $partials = explode('.', (string) $key);
                if (isset($partials[1]) && array_key_exists($partials[1], $oldRecord)) {
                    unset($oldRecord[$partials[1]]);
                    $values[$partials[1]] = $value;
                    unset($values[$key]);
                }
            }

            $timestamp = $updateTime->format('Y-m-d H:i:s.u');
            $merged = array_merge($oldRecord, $values, [$this->columnTrxDateFrom => $timestamp]);

            $query->update([
                $this->columnTrxDateTo => $updateTime->copy()->subMilliseconds(1)->format('Y-m-d H:i:s.u'),
            ]);

            parent::insert($merged);
            $count++;
        }

        return $count;
    }

    public function delete($id = null): int
    {
        if (! is_null($id)) {
            $from = is_string($this->from) ? $this->from : '';
            $this->where($from.'.id', '=', $id);
        }

        $this->applyBeforeQueryCallbacks();

        $now = new Carbon;
        $this->where($this->columnTrxDateTo, $this->maxTimestamp);

        return parent::update([$this->columnTrxDateTo => $now->subMilliseconds(1)->format('Y-m-d H:i:s.u')]);
    }

    /**
     * @param  Records  $values
     * @return Records
     */
    protected function setTransactionTimestamp(array $values): array
    {
        if ($this->calledByEloquent === false) {
            $timestamp = (new DateTime)->format('Y-m-d H:i:s.u');

            foreach ($values as $key => $value) {
                $value[$this->columnTrxDateFrom] = $timestamp;
                $value[$this->columnTrxDateTo] = $this->maxTimestamp;
                /** @var int|string $key */
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
