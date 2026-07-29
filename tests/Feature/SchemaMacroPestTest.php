<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('unitemporal macro adds known_from and known_to columns', function () {
    Schema::create('schema_macro_test', function ($table) {
        $table->unsignedBigInteger('id');
        $table->unitemporal();
        $table->string('name');
        $table->unitempIndexes();
    });

    $columns = Schema::getColumnListing('schema_macro_test');

    expect($columns)->toContain('known_from');
    expect($columns)->toContain('known_to');
    expect($columns)->toContain('id');
    expect($columns)->toContain('name');

    $indexes = DB::select("SELECT * FROM sqlite_master WHERE type = 'index' AND tbl_name = 'schema_macro_test'");
    $indexNames = array_map(fn ($i) => $i->name, $indexes);

    expect($indexNames)->toContain('schema_macro_test_known_to_id_index');
});

it('unitemporal works with custom id column name', function () {
    Schema::create('schema_macro_uuid_test', function ($table) {
        $table->uuid('uuid');
        $table->unitemporal();
        $table->string('name');
        $table->unitempIndexes('uuid');
    });

    $columns = Schema::getColumnListing('schema_macro_uuid_test');

    expect($columns)->toContain('known_from');
    expect($columns)->toContain('known_to');
    expect($columns)->toContain('uuid');
});

it('creates composite primary key on id, known_from, known_to', function () {
    Schema::create('schema_macro_pk_test', function ($table) {
        $table->unsignedBigInteger('id');
        $table->unitemporal();
        $table->string('name');
        $table->unitempIndexes();
    });

    $pragmas = DB::select('PRAGMA table_info(schema_macro_pk_test)');
    $pkColumns = [];

    foreach ($pragmas as $col) {
        if ($col->pk > 0) {
            $pkColumns[$col->pk] = $col->name;
        }
    }

    ksort($pkColumns);
    $pkColumns = array_values($pkColumns);

    expect($pkColumns)->toBe(['id', 'known_from', 'known_to']);
});
