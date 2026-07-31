<?php

use Guggach\LaravelDbTemporal\Tests\Models\BiTemporalItem;
use Illuminate\Support\Facades\DB;

const MAX_DATE = '9999-12-31';
const MAX_TS = '9999-12-31 23:59:59';

it('insert via Eloquent sets valid_from to today and valid_to to max_date', function () {
    $item = BiTemporalItem::create(['id' => 1, 'text' => 'Alpha']);

    expect($item->valid_from->format('Y-m-d'))->toBe(now()->format('Y-m-d'))
        ->and($item->valid_to->format('Y-m-d'))->toBe(MAX_DATE)
        ->and($item->known_to->format('Y-m-d H:i:s'))->toBe(MAX_TS);
});

it('insert with explicit valid_from preserves the provided date', function () {
    $item = BiTemporalItem::create(['id' => 1, 'text' => 'Alpha', 'valid_from' => '2024-04-01']);

    expect($item->valid_from->format('Y-m-d'))->toBe('2024-04-01')
        ->and($item->valid_to->format('Y-m-d'))->toBe(MAX_DATE);
});

it('insert with explicit valid_from and valid_to preserves both dates', function () {
    $item = BiTemporalItem::create([
        'id' => 1, 'text' => 'Alpha',
        'valid_from' => '2024-04-01', 'valid_to' => '2024-06-30',
    ]);

    expect($item->valid_from->format('Y-m-d'))->toBe('2024-04-01')
        ->and($item->valid_to->format('Y-m-d'))->toBe('2024-06-30');
});

it('two inserts get auto-incremented IDs', function () {
    $a = BiTemporalItem::create(['text' => 'Alpha']);
    $b = BiTemporalItem::create(['text' => 'Beta']);

    expect($a->id)->toBe(1)
        ->and($b->id)->toBe(2);
});

it('insertGetId via builder returns correct ID and sets temporal columns', function () {
    $builder = (new BiTemporalItem)->newModelQuery()->getQuery();

    $id = $builder->insertGetId(['text' => 'Alpha']);

    expect($id)->toBe(1);

    $row = DB::connection('sqlite-test')->table('bi_temporal_items')->first();
    expect($row->valid_to)->toBe(MAX_DATE)
        ->and($row->known_to)->toBe(MAX_TS);
});
