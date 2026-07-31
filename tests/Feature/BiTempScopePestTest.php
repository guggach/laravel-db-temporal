<?php

use Guggach\LaravelDbTemporal\Tests\Models\BiTemporalItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function seedBiTemporal(): void
{
    $t = 'bi_temporal_items';

    // Rec1 (TT-archived): original record, VT open-ended, TT-terminated on update
    // Rec1' (remainder): old address for VT Jan–Jul14, TT-open after update
    // Rec2 (current): new address from Jul15, TT-open
    DB::connection('sqlite-test')->table($t)->insert([
        ['id' => 1, 'text' => 'V1', 'valid_from' => '2024-01-01 00:00:00', 'valid_to' => '9999-12-31 00:00:00', 'known_from' => '2024-01-01 00:00:00', 'known_to' => '2024-07-14 23:59:59'],
        ['id' => 1, 'text' => 'V1', 'valid_from' => '2024-01-01 00:00:00', 'valid_to' => '2024-07-14 00:00:00', 'known_from' => '2024-07-15 00:00:00', 'known_to' => '9999-12-31 23:59:59'],
        ['id' => 1, 'text' => 'V2', 'valid_from' => '2024-07-15 00:00:00', 'valid_to' => '9999-12-31 00:00:00', 'known_from' => '2024-07-15 00:00:00', 'known_to' => '9999-12-31 23:59:59'],
    ]);
}

it('default scope returns only the currently valid record (TT-open + VT=today)', function () {
    seedBiTemporal();

    // Rec1' has valid_to=2024-07-14 (past) → filtered out by VT=today
    // Rec2 has valid_to=9999-12-31 (open) → visible
    expect(BiTemporalItem::where('id', 1)->count())->toBe(1);
});

it('allVersions removes global scope and returns all rows', function () {
    seedBiTemporal();

    expect(BiTemporalItem::allVersions()->where('id', 1)->count())->toBe(3);
});

it('currentVersion restores the TT-open filter', function () {
    seedBiTemporal();

    expect(BiTemporalItem::allVersions()->currentVersion()->where('id', 1)->count())->toBe(2);
});

it('firstVersion returns the earliest TT-version', function () {
    seedBiTemporal();

    $first = BiTemporalItem::firstVersion()->where('id', 1)->first();
    expect($first->known_from->format('Y-m-d'))->toBe('2024-01-01');
});

it('latestVersion returns the most recent TT-version', function () {
    seedBiTemporal();

    $latest = BiTemporalItem::latestVersion()->where('id', 1)->first();
    expect($latest->known_from->format('Y-m-d'))->toBe('2024-07-15');
});

it('versionAsOf returns records known at a given TT point', function () {
    seedBiTemporal();

    // Before the update was known: only 1 record for the pre-update TT period
    $result = BiTemporalItem::versionAsOf('2024-06-15')->where('id', 1)->get();
    expect($result)->toHaveCount(1)
        ->and($result->first()->valid_from->format('Y-m-d'))->toBe('2024-01-01');
});

it('validAsOf returns the currently known record covering a VT point', function () {
    seedBiTemporal();

    $result = BiTemporalItem::validAsOf('2024-05-01')->where('id', 1)->first();
    expect($result)->not->toBeNull()
        ->and($result->text)->toBe('V1');

    $result2 = BiTemporalItem::validAsOf('2024-09-01')->where('id', 1)->first();
    expect($result2)->not->toBeNull()
        ->and($result2->text)->toBe('V2');
});

it('asOf combines TT and VT point queries', function () {
    seedBiTemporal();

    // VT first, TT second (defaults to now)
    $result = BiTemporalItem::asOf('2024-05-01', '2024-06-15')->where('id', 1)->first();
    expect($result)->not->toBeNull()
        ->and($result->text)->toBe('V1');

    // At TT=2024-06-15, ask about VT=2024-08-01:
    // Rec1 VT is [Jan–∞] → covers Aug too → returns V1 (the system knew it was valid indefinitely)
    $resultAug = BiTemporalItem::asOf('2024-08-01', '2024-06-15')->where('id', 1)->first();
    expect($resultAug)->not->toBeNull()
        ->and($resultAug->text)->toBe('V1');

    // VT=2024-08-01, TT defaults to now() → V2 (post-update knowledge)
    $current = BiTemporalItem::asOf('2024-08-01')->where('id', 1)->first();
    expect($current)->not->toBeNull()
        ->and($current->text)->toBe('V2');
});

it('versionsInValidRange returns records whose VT is fully within the window', function () {
    seedBiTemporal();

    // Rec1' has VT [Jan–Jul14] which is fully within [Jan–Dec]
    $result = BiTemporalItem::versionsInValidRange('2024-01-01', '2024-12-31')->where('id', 1)->get();
    expect($result)->toHaveCount(1)
        ->and($result->first()->valid_to->format('Y-m-d'))->toBe('2024-07-14');
});

it('versionsTouchingValidRange returns records that overlap the window', function () {
    seedBiTemporal();

    // Both open records overlap with [2024-06-01, 2024-08-01]
    $result = BiTemporalItem::versionsTouchingValidRange('2024-06-01', '2024-08-01')->where('id', 1)->get();
    expect($result)->toHaveCount(2);
});

it('schema macros create correct column types and primary key', function () {
    Schema::create('bitemp_macro_test', function ($table) {
        $table->unsignedBigInteger('id');
        $table->bitemporal();
        $table->string('name');
        $table->bitempIndexes();
    });

    $columns = Schema::getColumnListing('bitemp_macro_test');
    expect($columns)->toContain('valid_from')
        ->toContain('valid_to')
        ->toContain('known_from')
        ->toContain('known_to');

    // SQLite stores date and datetime both as TEXT; verify the PK columns exist
    $pragmas = DB::select('PRAGMA table_info(bitemp_macro_test)');
    $pkCols = collect($pragmas)->filter(fn ($c) => $c->pk > 0)->pluck('name')->sort()->values()->toArray();
    expect($pkCols)->toBe(['id', 'known_to', 'valid_to']);
});
