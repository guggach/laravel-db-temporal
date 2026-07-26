<?php

namespace Guggach\LaravelDbTemporal\Connections;

use Guggach\LaravelDbTemporal\Builders\BiTempQueryBuilder;

class Connection extends \Illuminate\Database\MySqlConnection {
    //@Override
    public function query() {
        return new BiTempQueryBuilder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor()
        );
    }
}