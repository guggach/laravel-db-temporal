<?php

namespace Guggach\LaravelDbTemporal\Database\Query;

use BackedEnum;
use Carbon\Carbon;
use DateTime;
use DateTimeInterface;
use Guggach\LaravelDbTemporal\Configuration\BiTemporalConfig;
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
class BiTemporalBuilder extends Builder
{
    private string $columnValidFrom;

    private string $columnValidTo;

    private string $columnKnownFrom;

    private string $columnKnownTo;

    /** @var 'day'|'datetime' */
    private string $vtPrecision;

    private string $maxDate;

    private string $maxTimestamp;

    private bool $calledByEloquent = false;

    private bool $skipVersioning = false;

    public function __construct(
        ConnectionInterface $connection,
        ?Grammar $grammar = null,
        ?Processor $processor = null,
    ) {
        $this->columnValidFrom = 'valid_from';
        $this->columnValidTo = 'valid_to';
        $this->columnKnownFrom = 'known_from';
        $this->columnKnownTo = 'known_to';
        $this->vtPrecision = 'day';
        $this->maxDate = '9999-12-31';
        $this->maxTimestamp = '9999-12-31 23:59:59';

        parent::__construct($connection, $grammar, $processor);
    }

    public function setTemporalConfig(BiTemporalConfig $config, bool $calledByEloquent = false): void
    {
        $this->columnValidFrom = $config->columnValidFrom;
        $this->columnValidTo = $config->columnValidTo;
        $this->columnKnownFrom = $config->columnKnownFrom;
        $this->columnKnownTo = $config->columnKnownTo;
        /** @var 'day'|'datetime' $vt */
        $vt = $config->vtPrecision;
        $this->vtPrecision = $vt;
        $this->maxDate = $config->maxDate;
        $this->maxTimestamp = $config->maxTimestamp;
        $this->calledByEloquent = $calledByEloquent;
    }

    public function skipVersioning(): void
    {
        $this->skipVersioning = true;
    }

    public function resumeVersioning(): void
    {
        $this->skipVersioning = false;
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

        if (! $this->calledByEloquent) {
            $records = $this->applyTemporalDefaults($records);
        }

        $this->applyBeforeQueryCallbacks();

        return $this->connection->insert(
            $this->grammar->compileInsert($this, $records),
            $this->cleanBindings(Arr::flatten($records, 1)),
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

        $now = (new DateTime)->format('Y-m-d H:i:s');
        $nowVt = $this->vtPrecision === 'datetime'
            ? (new DateTime)->format('Y-m-d H:i:s')
            : (new DateTime)->format('Y-m-d').' 00:00:00';

        if (! array_key_exists($this->columnValidFrom, $values)) {
            $values[$this->columnValidFrom] = $nowVt;
        }
        if (! array_key_exists($this->columnValidTo, $values)) {
            $values[$this->columnValidTo] = $this->vtMaxSentinel();
        }
        if (! array_key_exists($this->columnKnownFrom, $values)) {
            $values[$this->columnKnownFrom] = $now;
        }
        if (! array_key_exists($this->columnKnownTo, $values)) {
            $values[$this->columnKnownTo] = $this->maxTimestamp;
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

        $newVtFrom = $this->extractVtString($values[$this->columnValidFrom] ?? null);
        $newVtTo = $this->extractVtString($values[$this->columnValidTo] ?? null);
        unset($values[$this->columnValidFrom], $values[$this->columnValidTo]);

        if ($this->vtPrecision === 'datetime') {
            $newVtTo = $this->normalizeVtToDatetime($newVtTo);
        }

        $now = new Carbon;

        if ($newVtFrom === null && $newVtTo === null) {
            return $this->applyPureTtVersioning($values, $now);
        }

        return $this->applyVtSplitting($values, $newVtFrom, $newVtTo, $now);
    }

    public function delete($id = null): int
    {
        if (! is_null($id)) {
            $from = is_string($this->from) ? $this->from : '';
            $this->where($from.'.id', '=', $id);
        }

        $this->applyBeforeQueryCallbacks();

        $now = new Carbon;
        $this->where($this->columnKnownTo, $this->maxTimestamp);

        return parent::update([$this->columnKnownTo => $now->subSecond()->format('Y-m-d H:i:s')]);
    }

    /**
     * Gültigkeit der vom Query getroffenen, aktuell bekannten Records
     * schliessen: die offene TT-Version wird terminiert und eine neue
     * TT-Version mit `valid_to = $validTo` (inklusive) eingefügt — ohne
     * Recht-Remainder. Ohne Argument wird die letzte gültige Grenze
     * (gestern bzw. jetzt−1s) verwendet, damit der Record ab sofort als
     * nicht mehr aktuell gilt. Der Query muss die Records bereits
     * einschränken (z.B. aktuell gültig + bekannte IDs).
     */
    public function closeValidityAt(Carbon|string|null $validTo = null): int
    {
        $date = $validTo === null
            ? $this->lastValidBoundary()
            : $this->normalizeVtBoundary($validTo);
        $now = new Carbon;
        $nowStr = $now->format('Y-m-d H:i:s');

        /** @var array<int, object> $oldRecs */
        $oldRecs = $this->buildFindQuery()->get();
        $count = 0;

        foreach ($oldRecs as $oldRec) {
            /** @var Record $oldRecord */
            $oldRecord = (array) $oldRec;
            $oldVtTo = $this->extractVtString($oldRecord[$this->columnValidTo] ?? null);

            $this->terminateByValidTo($oldVtTo, $now);

            parent::insert($this->normalizeVtRecord(array_merge($oldRecord, [
                $this->columnValidTo => $date,
                $this->columnKnownFrom => $nowStr,
                $this->columnKnownTo => $this->maxTimestamp,
            ])));

            $count++;
        }

        return $count;
    }

    /** Normalisiert eine VT-Grenze auf das Speicherformat (Tages-Präzision → 00:00:00). */
    private function normalizeVtBoundary(Carbon|string|null $value): string
    {
        $date = $value instanceof Carbon
            ? $value
            : ($value !== null ? new Carbon($value) : new Carbon);

        return $this->vtPrecision === 'datetime'
            ? $date->format('Y-m-d H:i:s')
            : $date->format('Y-m-d').' 00:00:00';
    }

    /** Letzte gültige Grenze „jetzt" (gestern bei Tages-Präzision, sonst jetzt−1s). */
    private function lastValidBoundary(): string
    {
        $now = new Carbon;

        return $this->vtPrecision === 'datetime'
            ? $now->subSecond()->format('Y-m-d H:i:s')
            : $now->subDay()->format('Y-m-d').' 00:00:00';
    }

    /**
     * Bi-temporal point query. VT first; TT defaults to now().
     */
    public function asOf(Carbon|string|null $vtDatetime, Carbon|string|null $ttDatetime = null): static
    {
        $tt = $ttDatetime !== null
            ? ($ttDatetime instanceof Carbon ? $ttDatetime : new Carbon($ttDatetime))
            : Carbon::now();
        $vt = $vtDatetime !== null
            ? ($vtDatetime instanceof Carbon ? $vtDatetime : new Carbon($vtDatetime))
            : null;

        $this->where($this->columnKnownFrom, '<=', $tt)
            ->where($this->columnKnownTo, '>=', $tt);

        if ($vt !== null) {
            $this->where($this->columnValidFrom, '<=', $vt)
                ->where($this->columnValidTo, '>=', $vt);
        }

        return $this;
    }

    /**
     * VT point query on currently known records.
     */
    public function validAsOf(Carbon|string $vtDatetime): static
    {
        $vt = $vtDatetime instanceof Carbon ? $vtDatetime : new Carbon($vtDatetime);

        return $this->where($this->columnKnownTo, $this->maxTimestamp)
            ->where($this->columnValidFrom, '<=', $vt)
            ->where($this->columnValidTo, '>=', $vt);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function vtMaxSentinel(): string
    {
        return $this->vtPrecision === 'datetime' ? $this->maxTimestamp : $this->maxDate;
    }

    /**
     * @param  Record  $record
     * @return Record
     */
    private function normalizeVtRecord(array $record): array
    {
        if ($this->vtPrecision === 'day') {
            // Normalize to 'Y-m-d 00:00:00' so all VT values have consistent storage format.
            // MySQL DATE columns truncate the time on write; SQLite stores as-is.
            foreach ([$this->columnValidFrom, $this->columnValidTo] as $col) {
                if (isset($record[$col]) && is_string($record[$col])) {
                    $record[$col] = substr($record[$col], 0, 10).' 00:00:00';
                }
            }
        }

        return $record;
    }

    /** Subtract one unit from a VT boundary value. */
    private function vtSubOne(string $value): string
    {
        if ($this->vtPrecision === 'datetime') {
            return Carbon::parse($value)->subSecond()->format('Y-m-d H:i:s');
        }

        return Carbon::parse($value)->subDay()->format('Y-m-d').' 00:00:00';
    }

    /** Add one unit to a VT boundary value. */
    private function vtAddOne(string $value): string
    {
        if ($this->vtPrecision === 'datetime') {
            return Carbon::parse($value)->addSecond()->format('Y-m-d H:i:s');
        }

        return Carbon::parse($value)->addDay()->format('Y-m-d').' 00:00:00';
    }

    /** Normalize a datetime VT input: if no time component given, append 23:59:59. */
    private function normalizeVtToDatetime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (strlen($value) === 10) {
            return $value.' 23:59:59';
        }

        return $value;
    }

    /**
     * @param  Records  $values
     * @return Records
     */
    private function applyTemporalDefaults(array $values): array
    {
        $nowTs = (new DateTime)->format('Y-m-d H:i:s');
        // Use midnight format for day-precision so storage is consistent with Eloquent's date formatter.
        $nowVt = $this->vtPrecision === 'datetime'
            ? (new DateTime)->format('Y-m-d H:i:s')
            : (new DateTime)->format('Y-m-d').' 00:00:00';
        $vtMax = $this->vtMaxSentinel();

        foreach ($values as $key => $value) {
            /** @var Record $value */
            if (! array_key_exists($this->columnValidFrom, $value)) {
                $value[$this->columnValidFrom] = $nowVt;
            }
            if (! array_key_exists($this->columnValidTo, $value)) {
                $value[$this->columnValidTo] = $vtMax;
            }
            if (! array_key_exists($this->columnKnownFrom, $value)) {
                $value[$this->columnKnownFrom] = $nowTs;
            }
            if (! array_key_exists($this->columnKnownTo, $value)) {
                $value[$this->columnKnownTo] = $this->maxTimestamp;
            }
            /** @var int|string $key */
            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * Pure TT-versioning: no VT change, one new TT-version per matched record.
     *
     * @param  Record  $values
     */
    private function applyPureTtVersioning(array $values, Carbon $now): int
    {
        $findQuery = $this->buildFindQuery();
        /** @var array<int, object> $oldRecs */
        $oldRecs = $findQuery->get();
        $nowStr = $now->format('Y-m-d H:i:s');
        $count = 0;

        foreach ($oldRecs as $oldRec) {
            /** @var Record $oldRecord */
            $oldRecord = (array) $oldRec;

            $this->normalizeDottedKeys($values, $oldRecord);

            $newRecord = $this->normalizeVtRecord(array_merge($oldRecord, $values, [
                $this->columnKnownFrom => $nowStr,
                $this->columnKnownTo => $this->maxTimestamp,
            ]));

            $this->terminateByValidTo($this->extractVtString($oldRecord[$this->columnValidTo] ?? null), $now);

            parent::insert($newRecord);
            $count++;
        }

        return $count;
    }

    /**
     * VT-splitting: find overlapping records, create remainders, TT-terminate, insert new.
     *
     * @param  Record  $values
     */
    private function applyVtSplitting(array $values, ?string $newVtFrom, ?string $newVtTo, Carbon $now): int
    {
        // Strip time component for day-precision VT (Carbon __toString includes time)
        if ($this->vtPrecision === 'day') {
            if ($newVtFrom !== null) {
                $newVtFrom = substr($newVtFrom, 0, 10);
            }
            if ($newVtTo !== null) {
                $newVtTo = substr($newVtTo, 0, 10);
            }
        }

        $vtMax = $this->vtMaxSentinel();
        $nowStr = $now->format('Y-m-d H:i:s');

        $findQuery = $this->buildFindQuery();
        if ($newVtFrom !== null) {
            $findQuery->where($this->columnValidTo, '>=', $newVtFrom);
        }
        if ($newVtTo !== null && $newVtTo !== $vtMax) {
            $findQuery->where($this->columnValidFrom, '<=', $newVtTo);
        }

        /** @var array<int, object> $oldRecs */
        $oldRecs = $findQuery->get();
        $count = 0;
        $baseRecord = null;

        foreach ($oldRecs as $oldRec) {
            /** @var Record $oldRecord */
            $oldRecord = (array) $oldRec;
            $oldVtFrom = $this->extractVtString($oldRecord[$this->columnValidFrom] ?? null);
            $oldVtTo = $this->extractVtString($oldRecord[$this->columnValidTo] ?? null);

            if ($baseRecord === null) {
                $baseRecord = $oldRecord;
                // Normalize dotted keys (e.g. "table.updated_at" → "updated_at") from Eloquent
                $this->normalizeDottedKeys($values, $baseRecord);
            }

            $this->terminateByValidTo($oldVtTo, $now);

            // Left remainder: preserve old data for VT range before new valid_from
            if ($newVtFrom !== null && $oldVtFrom !== null && $oldVtFrom < $newVtFrom) {
                parent::insert($this->normalizeVtRecord(array_merge($oldRecord, [
                    $this->columnValidTo => $this->vtSubOne($newVtFrom),
                    $this->columnKnownFrom => $nowStr,
                    $this->columnKnownTo => $this->maxTimestamp,
                ])));
            }

            // Right remainder: preserve old data for VT range after new valid_to
            // Termination must happen BEFORE this insert because the right remainder
            // shares the same valid_to as the old record (same PK slot).
            if ($newVtTo !== null && $newVtTo !== $vtMax && $oldVtTo !== null && $oldVtTo > $newVtTo) {
                parent::insert($this->normalizeVtRecord(array_merge($oldRecord, [
                    $this->columnValidFrom => $this->vtAddOne($newVtTo),
                    $this->columnKnownFrom => $nowStr,
                    $this->columnKnownTo => $this->maxTimestamp,
                ])));
            }
            $count++;
        }

        if ($count === 0) {
            return 0;
        }

        $vtFormat = $this->vtPrecision === 'datetime' ? 'Y-m-d H:i:s' : 'Y-m-d';
        $newRecord = $this->normalizeVtRecord(array_merge(
            $baseRecord,
            $values,
            [
                $this->columnValidFrom => $newVtFrom ?? ($now->format($vtFormat).($this->vtPrecision === 'day' ? ' 00:00:00' : '')),
                $this->columnValidTo => $newVtTo ?? $vtMax,
                $this->columnKnownFrom => $nowStr,
                $this->columnKnownTo => $this->maxTimestamp,
            ]
        ));

        parent::insert($newRecord);

        return $count;
    }

    /** Build the base query to find TT-open records matching current WHERE conditions. */
    private function buildFindQuery(): Builder
    {
        $q = new Builder($this->connection, $this->grammar, $this->processor);
        $q->from($this->from);
        $q->wheres = $this->wheres;
        $q->bindings = $this->bindings;
        $q->where($this->columnKnownTo, $this->maxTimestamp);

        return $q;
    }

    /** TT-terminate a specific record identified by its valid_to value (part of the PK). */
    private function terminateByValidTo(?string $validTo, Carbon $now): void
    {
        if ($validTo === null) {
            return;
        }
        $q = new Builder($this->connection, $this->grammar, $this->processor);
        $q->from($this->from);
        $q->wheres = $this->wheres;
        $q->bindings = $this->bindings;
        $q->where($this->columnValidTo, $validTo);
        $q->where($this->columnKnownTo, $this->maxTimestamp);
        $q->update([$this->columnKnownTo => $now->copy()->subSecond()->format('Y-m-d H:i:s')]);
    }

    /**
     * Convert a RecordValue to string for use as a VT boundary; returns null for arrays/null.
     *
     * @param  RecordValue  $value
     */
    private function extractVtString(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : (string) $value;
    }

    /**
     * @param  Record  $record
     * @param  Record  $values
     */
    private function normalizeDottedKeys(array &$values, array $record): void
    {
        foreach ($values as $key => $value) {
            $partials = explode('.', (string) $key);
            if (isset($partials[1]) && array_key_exists($partials[1], $record)) {
                $values[$partials[1]] = $value;
                unset($values[$key]);
            }
        }
    }
}
