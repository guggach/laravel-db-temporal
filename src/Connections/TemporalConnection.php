<?php

namespace Guggach\LaravelDbTemporal\Connections;

use Guggach\LaravelDbTemporal\Database\Query\UniTemporalBuilder;
use Illuminate\Database\Connection;

class TemporalConnection extends Connection
{
    protected Connection $baseConnection;

    protected array $uniTemporalTables;

    protected array $uniTemporalDefaults;

    public function __construct(Connection $baseConnection, array $config = [])
    {
        $this->baseConnection = $baseConnection;

        parent::__construct(
            $baseConnection->getPdo(),
            $baseConnection->getDatabaseName(),
            $baseConnection->getTablePrefix(),
            $config
        );

        $this->readPdo = $baseConnection->getReadPdo();
        $this->setQueryGrammar($baseConnection->getQueryGrammar());
        $this->setPostProcessor($baseConnection->getPostProcessor());
        $this->setSchemaGrammar($baseConnection->getSchemaGrammar());

        $this->uniTemporalTables = $config['uni-temporal']['tables'] ?? [];
        $this->uniTemporalDefaults = $config['uni-temporal']['defaults'] ?? [];
    }

    public function getUniTemporalTableConfig(string $table): ?array
    {
        return $this->uniTemporalTables[$table] ?? null;
    }

    public function getUniTemporalDefaults(): array
    {
        return $this->uniTemporalDefaults;
    }

    public function getDriverName(): string
    {
        return 'temporal-proxy';
    }

    public function getBaseConnection(): Connection
    {
        return $this->baseConnection;
    }

    public function table($table, $as = null)
    {
        $query = parent::table($table, $as);

        $tableConfig = $this->uniTemporalTables[$table] ?? null;

        if ($tableConfig !== null) {
            $temporalQuery = new UniTemporalBuilder(
                $this,
                $this->getQueryGrammar(),
                $this->getPostProcessor()
            );

            $columnFrom = $tableConfig['column_from']
                ?? $this->uniTemporalDefaults['column_from']
                ?? 'known_from';
            $columnTo = $tableConfig['column_to']
                ?? $this->uniTemporalDefaults['column_to']
                ?? 'known_to';
            $maxTimestamp = $tableConfig['max_timestamp']
                ?? $this->uniTemporalDefaults['max_timestamp']
                ?? '9999-12-31 23:59:59';

            $temporalQuery->setTemporalColumnNames($columnFrom, $columnTo, $maxTimestamp, false);
            $temporalQuery->from($table, $as);

            return $temporalQuery;
        }

        return $query;
    }
}
