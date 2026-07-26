<?php

// config for Guggach/LaravelDbTemporal
return [
    'defaults' => [

        'columnValidDateFrom' => 'valid_from',
        'columnValidDateTo' => 'valid_to',

        // ColumnTRXDate -From / -TO is system given automatically
        // This holds the History of a tuple (record with same Id)
        // SQL92 names this SYSTEM_TIME VERSION with ROW START and ROW END
        // Often used synonyms are:
        // -transaction_start_ts / transaction_end_ts
        // -row_start / row_end
        // -inserted_at / deleted_at (could be confusing with Softdelete
        // -known_from / known_to or Known_until
        // -belive_from / belive_to
        'columnTrxDateFrom' => 'known_from',
        'columnTrxDateTo' => 'known_to',
        'maxTimestamp' => '9999-12-31 23:59:59',
        'maxDate' => '9999-12-31',
        'validRangeGranularity' => 'date',  // or 'datetime'
    ]
];
