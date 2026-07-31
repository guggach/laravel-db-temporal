<?php

use Guggach\LaravelDbTemporal\Tests\Models\BiTemporalItem;
use Illuminate\Support\Facades\DB;

it('delete closes known_to and leaves valid_to unchanged', function () {
    $item = BiTemporalItem::create(['id' => 1, 'text' => 'Alpha', 'valid_from' => '2024-04-01']);
    sleep(1);

    $item->delete();

    $row = DB::connection('sqlite-test')
        ->table('bi_temporal_items')
        ->where('id', 1)
        ->orderByDesc('known_from')
        ->first();

    expect($row->known_to)->not->toBe('9999-12-31 23:59:59')
        ->and(substr($row->valid_to, 0, 10))->toBe('9999-12-31'); // VT untouched (SQLite may store as date or datetime)
});

it('delete does not physically remove the row', function () {
    $item = BiTemporalItem::create(['id' => 1, 'text' => 'Alpha']);
    sleep(1);
    $item->delete();

    $count = DB::connection('sqlite-test')->table('bi_temporal_items')->where('id', 1)->count();
    expect($count)->toBe(1); // row still exists
});

it('deleted item is invisible via default scope', function () {
    $item = BiTemporalItem::create(['id' => 1, 'text' => 'Alpha']);
    sleep(1);
    $item->delete();

    expect(BiTemporalItem::where('id', 1)->first())->toBeNull();
});

it('deleted item is visible via allVersions', function () {
    $item = BiTemporalItem::create(['id' => 1, 'text' => 'Alpha']);
    sleep(1);
    $item->delete();

    expect(BiTemporalItem::allVersions()->where('id', 1)->count())->toBe(1);
});

it('delete after update leaves update history intact', function () {
    BiTemporalItem::create(['id' => 1, 'text' => 'V1', 'valid_from' => '2024-04-01']);
    sleep(1);

    $item = BiTemporalItem::where('id', 1)->first();
    $item->text = 'V2';
    $item->valid_from = '2024-07-15';
    $item->save();
    sleep(1);

    BiTemporalItem::latestVersion()->where('id', 1)->first()->delete();

    // 3 rows: Rec1(archived), Rec1'(terminated), Rec2(terminated)
    expect(BiTemporalItem::allVersions()->where('id', 1)->count())->toBe(3);

    // ALL TT-open records are terminated — entity is fully invisible
    $openCount = DB::connection('sqlite-test')
        ->table('bi_temporal_items')
        ->where('id', 1)
        ->where('known_to', '9999-12-31 23:59:59')
        ->count();
    expect($openCount)->toBe(0);

    // validAsOf any date returns null — consistent "entity is gone" behaviour
    expect(BiTemporalItem::validAsOf('2024-05-15')->where('id', 1)->first())->toBeNull();
    expect(BiTemporalItem::validAsOf(now())->where('id', 1)->first())->toBeNull();
});
