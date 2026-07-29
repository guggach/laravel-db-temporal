<?php

namespace Guggach\LaravelDbTemporal\Connections;

use Guggach\LaravelDbTemporal\Database\Query\UniTemporalBuilder;
use Illuminate\Database\Connection;

class TemporalConnection extends Connection
{
    protected Connection $baseConnection;

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $uniTemporalTables = [];

    /**
     * @var array<string, mixed>
     */
    protected array $uniTemporalDefaults = [];

    /**
     * @param  array<string, mixed>  $config
     */
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

        $uniTemporal = is_array($config['uni-temporal'] ?? null) ? $config['uni-temporal'] : [];
        /** @var array<string, array<string, mixed>> $tables */
        $tables = is_array($uniTemporal['tables'] ?? null) ? $uniTemporal['tables'] : [];
        $this->uniTemporalTables = $tables;
        /** @var array<string, mixed> $defaults */
        $defaults = is_array($uniTemporal['defaults'] ?? null) ? $uniTemporal['defaults'] : [];
        $this->uniTemporalDefaults = $defaults;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUniTemporalTableConfig(string $table): ?array
    {
        return $this->uniTemporalTables[$table] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
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
        if (! is_string($table)) {
            return parent::table($table, $as);
        }

        $query = parent::table($table, $as);

        $tableConfig = $this->uniTemporalTables[$table] ?? null;

        if ($tableConfig !== null) {
            $temporalQuery = new UniTemporalBuilder(
                $this,
                $this->getQueryGrammar(),
                $this->getPostProcessor()
            );

            $columnFrom = $this->stringValue($tableConfig['column_from']
                ?? $this->uniTemporalDefaults['column_from']
                ?? 'known_from');
            $columnTo = $this->stringValue($tableConfig['column_to']
                ?? $this->uniTemporalDefaults['column_to']
                ?? 'known_to');
            $maxTimestamp = $this->stringValue($tableConfig['max_timestamp']
                ?? $this->uniTemporalDefaults['max_timestamp']
                ?? '9999-12-31 23:59:59');

            $temporalQuery->setTemporalColumnNames($columnFrom, $columnTo, $maxTimestamp, false);
            $temporalQuery->from($table, $as);

            return $temporalQuery;
        }

        return $query;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
