<?php

namespace Guggach\LaravelDbTemporal\Connections;

use Guggach\LaravelDbTemporal\Configuration\BiTemporalConfig;
use Guggach\LaravelDbTemporal\Configuration\TemporalConfig;
use Guggach\LaravelDbTemporal\Database\Query\BiTemporalBuilder;
use Guggach\LaravelDbTemporal\Database\Query\UniTemporalBuilder;
use Illuminate\Database\Connection;

/**
 * @phpstan-import-type TemporalConfigShape from TemporalConfig
 * @phpstan-import-type TemporalTablesShape from TemporalConfig
 * @phpstan-import-type TemporalConnectionConfigShape from TemporalConfig
 * @phpstan-import-type BiTemporalConfigShape from BiTemporalConfig
 * @phpstan-import-type BiTemporalTablesShape from BiTemporalConfig
 */
class TemporalConnection extends Connection
{
    protected Connection $baseConnection;

    protected TemporalConfig $defaultConfig;

    /**
     * @var TemporalTablesShape
     */
    protected array $tableConfigs = [];

    protected BiTemporalConfig $biTemporalDefaults;

    /**
     * @var BiTemporalTablesShape
     */
    protected array $biTemporalTableConfigs = [];

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

        // The base connection's schema grammar is lazy: until the schema
        // builder was used once, getSchemaGrammar() may return null even
        // though the vendor types say otherwise. Materialise it deterministically
        // before the proxy inherits it.
        $baseConnection->useDefaultSchemaGrammar();
        $this->setSchemaGrammar($baseConnection->getSchemaGrammar());

        $this->defaultConfig = TemporalConfig::fromArray($config['uni-temporal']['defaults'] ?? null);
        $this->tableConfigs = $config['uni-temporal']['tables'] ?? [];

        /** @var array{defaults?: BiTemporalConfigShape, tables?: BiTemporalTablesShape} $biConfig */
        $biConfig = $config['bi-temporal'] ?? [];
        $this->biTemporalDefaults = BiTemporalConfig::fromArray($biConfig['defaults'] ?? null);
        $this->biTemporalTableConfigs = $biConfig['tables'] ?? [];
    }

    public function getUniTemporalTableConfig(string $table): ?TemporalConfig
    {
        return isset($this->tableConfigs[$table])
            ? TemporalConfig::fromArray($this->tableConfigs[$table])
            : null;
    }

    /**
     * Schema-builder work (migrations, Schema:: facade, db:wipe) must run on
     * the base connection: the proxy is not a driver-specific connection and
     * the base connection provides the driver-specific builder (e.g.
     * PostgresBuilder with dropAllTables support).
     */
    public function getSchemaBuilder()
    {
        return $this->baseConnection->getSchemaBuilder();
    }

    /**
     * The custom driver resolver receives the parsed connection config
     * without a "name" key, so this connection cannot resolve its own name
     * from config. The base connection carries the correct name (it is
     * created through the ConnectionFactory), so delegate identity to it —
     * the migrator relies on getName()/getNameWithReadWriteType() to switch
     * the default connection while running migrations.
     */
    public function getName()
    {
        return $this->baseConnection->getName();
    }

    public function getUniTemporalDefaults(): TemporalConfig
    {
        return $this->defaultConfig;
    }

    public function getBiTemporalTableConfig(string $table): ?BiTemporalConfig
    {
        return isset($this->biTemporalTableConfigs[$table])
            ? BiTemporalConfig::fromArray($this->biTemporalTableConfigs[$table])
            : null;
    }

    public function getBiTemporalDefaults(): BiTemporalConfig
    {
        return $this->biTemporalDefaults;
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

        // Bi-temporal tables take precedence over uni-temporal
        if (isset($this->biTemporalTableConfigs[$table])) {
            $biConfig = BiTemporalConfig::fromArray($this->biTemporalTableConfigs[$table]);
            $query = new BiTemporalBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
            $query->setTemporalConfig($biConfig);
            $query->from($table, $as);

            return $query;
        }

        if (isset($this->tableConfigs[$table])) {
            $uniConfig = TemporalConfig::fromArray($this->tableConfigs[$table]);
            $query = new UniTemporalBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
            $query->setTemporalConfig($uniConfig);
            $query->from($table, $as);

            return $query;
        }

        return parent::table($table, $as);
    }
}
