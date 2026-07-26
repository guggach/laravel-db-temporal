<?php

namespace Guggach\LaravelDbTemporal\Connections;

use Guggach\LaravelDbTemporal\Builders\BiTempQueryBuilder;
use Illuminate\Database\MySqlConnection;

class Connection extends MySqlConnection
{
    // @Override
    public function query()
    {
        return new BiTempQueryBuilder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor()
        );
    }
}
