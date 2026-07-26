<?php

namespace Guggach\LaravelDbTemporal\Database\Query;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UniTemporalBuilder extends Builder
{
    // private string $attributeSysFrom = config('db-temporal.defaults.attributeSysFrom');
    // private string $attributeSysTo = config('db-temporal.defaults.attributeSysTo');
    // private string $maxTimestamp = config('db-temporal.defaults.maxTimestamp');

    private string $columnTrxDateFrom;

    private string $columnTrxDateTo;

    private string $maxTimestamp;

    private bool $calledByEloquent = false;

    public function __construct(ConnectionInterface $connection,
        ?Grammar $grammar = null,
        ?Processor $processor = null)
    {
        $this->connection = $connection;
        $this->grammar = $grammar ?: $connection->getQueryGrammar();
        $this->processor = $processor ?: $connection->getPostProcessor();

        $this->columnTrxDateFrom = config('db-temporal.defaults.columnTrxDateFrom') ?? 'trx_date_from';
        $this->columnTrxDateTo = config('db-temporal.defaults.columnTrxDateTo') ?? 'trx_date_to';
        $this->maxTimestamp = config('db-temporal.defaults.maxTimestamp');
    }

    public function setTemporalColumnNames(?string $columnTrxDateFrom = null, ?string $columnTrxDateTo = null,
        ?string $maxTimestamp = null, bool $calledByEloquent = false)
    {

        $this->columnTrxDateFrom = $columnTrxDateFrom ?? $this->columnTrxDateFrom;
        $this->columnTrxDateTo = $columnTrxDateTo ?? $this->columnTrxDateTo;
        $this->maxTimestamp = $maxTimestamp ?? $this->maxTimestamp;
        $this->calledByEloquent = $calledByEloquent;
    }

    // insert()
    // -> add current timestamp in sysFrom + maxdate in sysTo
    /**
     * Insert new records into the database with unitemporal timestamps.
     * Record versioning in one dimension belive or known from until belive to or known until
     *
     * @return bool
     */
    public function insert(array $values)
    {

        // Since every insert gets treated like a batch insert, we will make sure the
        // bindings are structured in a way that is convenient when building these
        // inserts statements by verifying these elements are actually an array.
        if (empty($values)) {
            return true;
        }

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        // Here, we will sort the insert keys for every record so that each insert is
        // in the same order for the record. We need to make sure this is the case
        // so there are not any errors or problems when inserting these records.
        else {
            foreach ($values as $key => $value) {
                ksort($value);

                $values[$key] = $value;
            }
        }

        // Ensures that the two columns for transactions date are set by the system
        // only changed function
        $values = $this->setTransactionTimestamp($values);

        $this->applyBeforeQueryCallbacks();

        // Finally, we will run this query against the database connection and return
        // the results. We will need to also flatten these bindings before running
        // the query so they are all in one huge, flattened array for execution.
        return $this->connection->insert(
            $this->grammar->compileInsert($this, $values),
            $this->cleanBindings(Arr::flatten($values, 1))
        );

    }

    /**
     * Insert a new record and get the value of the primary key.
     * Produce the Id by own function, because autoincrement can not be overriden in DB (exceptionally MySQL)
     *
     * @param  string|null  $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $this->applyBeforeQueryCallbacks();

        // $newId = DB::connection($this->getConnection()->getName())->table($this->from)->max($sequence) ?? 0;
        $newId = $this->max('id') ?? 0;
        $newId++;

        $values[$sequence] = $newId;

        $this->insert($values);

        return $newId;

    }

    // update()
    // -> search current record
    // -> update the found record with sysTo = current timestamp minus 1 sec.
    // -> insert new record with updated attributes and sysFrom = current timestamp and sysTo = max timestamp
    /**
     * Update records in the database.
     *
     * @return int
     */
    public function update(array $values)
    {
        $this->applyBeforeQueryCallbacks();

        // Check that only latest record is updated --> not possible
        // if ($this->{$this->columnTrxDateTo} != Carbon::parse($this->maxTimestamp)) {
        //     throw new \Exception('You try to update a historical database record in unitemporal database table. Only latest record can be updated.');
        // }

        // get all attributes of previous latest record
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

            // hack to eliminate duplicate with prefixed updated_at column from Eloquent
            // hack prefixed columns are not allowed in SQL language
            foreach ($values as $key => $value) {
                $partials = explode('.', $key);
                if (isset($partials[1])) {
                    if (array_key_exists($partials[1], $oldRec)) {
                        unset($oldRec[$partials[1]]);
                    }
                    $values[$partials[1]] = $value;
                    unset($values[$key]);
                }
            }

            // Merge values from previous record with updated values
            $values = array_merge($oldRec, $values, [$this->columnTrxDateFrom => $updateTime->format('Y-m-d H:i:s')]);

            // Update transaction to date or old record
            $query->update([$this->columnTrxDateTo => $updateTime->subSecond()->format('Y-m-d H:i:s')]);

            // insert new record with changed values
            parent::insert($values);
            $count++;

        }

        return $count;

        // return $this->connection->insert(
        //     $this->grammar->compileInsert($this, $values),
        //     $this->cleanBindings(Arr::flatten($values, 1))
        // );

    }

    /**
     * Delete records from the database.
     * temporal table does not delete physically it terminates transaction time to
     * Proper way: use Laravel's Softdelete trait
     *
     * @param  mixed  $id
     * @return int
     */
    public function delete($id = null)
    {
        // If an ID is passed to the method, we will set the where clause to check the
        // ID to let developers to simply and quickly remove a single row from this
        // database without manually specifying the "where" clauses on the query.
        if (! is_null($id)) {
            $this->where($this->from.'.id', '=', $id);
        }

        $this->applyBeforeQueryCallbacks();

        $now = new Carbon;

        $this->where($this->columnTrxDateTo, $this->maxTimestamp);

        return parent::update([$this->columnTrxDateTo => $now->subSecond()->format('Y-m-d H:i:s')]);

    }

    // delete()
    // -> search current record
    // -> update the found record with sysTo = current timestamp minus 1 sec.

    protected function setTransactionTimestamp($values): array
    {
        if ($this->calledByEloquent === false) {
            $now = new \DateTime;
            $timestamp = $now->format('Y-m-d H:i:s');
            for ($i = 0; $i < count($values); $i++) {
                $values[$i][$this->columnTrxDateFrom] = $timestamp;
                $values[$i][$this->columnTrxDateTo] = $this->maxTimestamp;
            }
        }

        return $values;
    }
}
