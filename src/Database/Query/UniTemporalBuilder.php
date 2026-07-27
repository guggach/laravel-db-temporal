<?php

namespace Guggach\LaravelDbTemporal\Database\Query;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Support\Arr;

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
        ?Processor $processor = null
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
        bool $calledByEloquent = false
    ): void {
        $this->columnTrxDateFrom = $columnTrxDateFrom ?? $this->columnTrxDateFrom;
        $this->columnTrxDateTo = $columnTrxDateTo ?? $this->columnTrxDateTo;
        $this->maxTimestamp = $maxTimestamp ?? $this->maxTimestamp;
        $this->calledByEloquent = $calledByEloquent;
    }

    public function insert(array $values): bool
    {
        if (empty($values)) {
            return true;
        }

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        foreach ($values as $key => $value) {
            ksort($value);
            $values[$key] = $value;
        }

        $values = $this->setTransactionTimestamp($values);

        $this->applyBeforeQueryCallbacks();

        return $this->connection->insert(
            $this->grammar->compileInsert($this, $values),
            $this->cleanBindings(Arr::flatten($values, 1))
        );
    }

    public function insertGetId(array $values, $sequence = null): int
    {
        $this->applyBeforeQueryCallbacks();

        $sequence = $sequence ?? 'id';

        $newId = ($this->max($sequence) ?? 0) + 1;

        $values[$sequence] = $newId;

        $this->insert($values);

        return $newId;
    }

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
        $oldRecs = $query->get();

        $updateTime = new Carbon;
        $count = 0;

        foreach ($oldRecs as $oldRec) {
            $oldRec = (array) $oldRec;

            foreach ($values as $key => $value) {
                $partials = explode('.', $key);
                if (isset($partials[1]) && array_key_exists($partials[1], $oldRec)) {
                    unset($oldRec[$partials[1]]);
                    $values[$partials[1]] = $value;
                    unset($values[$key]);
                }
            }

            $timestamp = $updateTime->format('Y-m-d H:i:s');

            $merged = array_merge($oldRec, $values, [$this->columnTrxDateFrom => $timestamp]);

            $query->update([
                $this->columnTrxDateTo => $updateTime->copy()->subSecond()->format('Y-m-d H:i:s'),
            ]);

            parent::insert($merged);
            $count++;
        }

        return $count;
    }

    public function delete($id = null): int
    {
        if (! is_null($id)) {
            $this->where($this->from . '.id', '=', $id);
        }

        $this->applyBeforeQueryCallbacks();

        $now = new Carbon;

        $this->where($this->columnTrxDateTo, $this->maxTimestamp);

        return parent::update([$this->columnTrxDateTo => $now->subSecond()->format('Y-m-d H:i:s')]);
    }

    protected function setTransactionTimestamp(array $values): array
    {
        if ($this->calledByEloquent === false) {
            $timestamp = (new \DateTime)->format('Y-m-d H:i:s');

            for ($i = 0; $i < count($values); $i++) {
                $values[$i][$this->columnTrxDateFrom] = $timestamp;
                $values[$i][$this->columnTrxDateTo] = $this->maxTimestamp;
            }
        }

        return $values;
    }
}
