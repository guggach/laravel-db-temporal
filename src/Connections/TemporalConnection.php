<?php

namespace Guggach\LaravelDbTemporal\Connections;

use Guggach\LaravelDbTemporal\Configuration\TemporalConfig;
use Guggach\LaravelDbTemporal\Database\Query\UniTemporalBuilder;
use Illuminate\Database\Connection;

/**
 * @phpstan-import-type TemporalConfigShape from TemporalConfig
 * @phpstan-import-type TemporalTablesShape from TemporalConfig
 * @phpstan-import-type TemporalConnectionConfigShape from TemporalConfig
 */
class TemporalConnection extends Connection
{
    protected Connection $baseConnection;

    protected TemporalConfig $defaultConfig;

    /**
     * @var TemporalTablesShape
     */
    protected array $tableConfigs = [];

    /**
     * @param  TemporalConnectionConfigShape  $config
     */
    public function __construct(Connection $baseConnection, array $config = [])
    {
        $this->baseConnection = $baseConnection;

        parent::__construct(
            $baseConnection->getPdo(),
            $baseConnection->getDatabaseName(),
            $baseConnection->getTablePrefix(),
            $config,
        );

        $this->readPdo = $baseConnection->getReadPdo();
        $this->setQueryGrammar($baseConnection->getQueryGrammar());
        $this->setPostProcessor($baseConnection->getPostProcessor());
        $this->setSchemaGrammar($baseConnection->getSchemaGrammar());

        $this->defaultConfig = TemporalConfig::fromArray($config['uni-temporal']['defaults'] ?? null);
        $this->tableConfigs = $config['uni-temporal']['tables'] ?? [];
    }

    /**
     * @phpstan-import-type TemporalTablesShape from TemporalConfig
     * @phpstan-import-type TemporalConnectionConfigShape from TemporalConfig
     */
    public function getUniTemporalTableConfig(string $table): ?TemporalConfig
    {
        return isset($this->tableConfigs[$table])
            ? TemporalConfig::fromArray($this->tableConfigs[$table])
            : null;
    }

    public function getUniTemporalDefaults(): TemporalConfig
    {
        return $this->defaultConfig;
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

        $tableConfig = isset($this->tableConfigs[$table])
            ? TemporalConfig::fromArray($this->tableConfigs[$table])
            : null;

        if ($tableConfig !== null) {
            $temporalQuery = new UniTemporalBuilder(
                $this,
                $this->getQueryGrammar(),
                $this->getPostProcessor()
            );

            $temporalQuery->setTemporalConfig($tableConfig);
            $temporalQuery->from($table, $as);

            return $temporalQuery;
        }

        return $query;
    }
}
