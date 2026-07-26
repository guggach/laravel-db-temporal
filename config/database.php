<?php

// config for Guggach/LaravelDbTemporal
return [
    'connections' => [
        'bitemp' => [
            'driver' => 'bitemp',
            'baseConnectionName' => 'sqlite',
            'options' => [
                'baseConnectionClass' =>'',
                'queryBuilder' => '',

            ],
            'tableoptions' => [
                'prefix' => '',
                'fact_from' => 'valid_from',
                'fact_to' => 'valid_until',
                'known_from' => 'belive_from',
                'known_until' => 'belive_until'
            ]
        ]

    ]

];
