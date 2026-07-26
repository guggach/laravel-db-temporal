<?php

namespace Guggach\LaravelDbTemporal;

use Exception;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\App;
use PhpParser\Node\Expr\Instanceof_;
use Guggach\LaravelDbTemporal\Connections\MySqlConnection;
use InvalidArgumentException;

class ConnectionFactory
{
    
    private $config;
    private $baseConfig;
    private $connctionName;
    private $baseConnectionName;
    private $app;

    public function __construct(App $app, Array $config, string $connectionName)
    {
        $this->app = $app;
        $this->connctionName = $connectionName;
        $this->config = $config[$connectionName];
        $this->baseConnectionName = $this->config['baseConnectionName'];
        $this->baseConfig = $config[$this->baseConnectionName];
    }

    private function getBaseConnection() 
    {
        if (!isset($this->baseConfig)){
            throw new InvalidArgumentException('The base connection "' . $this->baseConnectionName . '" is not defined.');
        }


        if(!empty($this->config['options']['baseConnectionClass'])) {
            if (!is_subclass_of($this->config['options']['baseConnection']::class, Connection::class)){
                throw new Exception('The option "' . $this->config['options']['baseConnection'] . '" in
                database config is not inherited from Laravel\'s connection.');
            }
        }
        return $this->app['db.factory']->make($this->baseConfig, $this->baseConnectionName);
        




    }

    // return match ($this->baseConfig['driver']) {
    //     'mysql' => new MySqlConnection,
    //     'pgsql' => new PostgresConnection,
    //     'sqlite' => new SQLiteConnectorion,
    //     'sqlsrv' => new SqlServerConnectorion,
    //     default => throw new InvalidArgumentException("Unsupported driver [{$config['driver']}]."),
    // };

}
