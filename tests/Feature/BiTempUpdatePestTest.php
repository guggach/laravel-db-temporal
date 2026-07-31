<?php

use Guggach\LaravelDbTemporal\Tests\Models\BiTemporalItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function insertRaw(int $id, string $text, string $validFrom, string $validTo, string $knownFrom, string $knownTo): void
{
    // Store VT values as 'Y-m-d 00:00:00' to match the builder's storage format.
    $vf = strlen($validFrom) === 10 ? $validFrom.' 00:00:00' : $validFrom;
    $vt = strlen($validTo) === 10 ? $validTo.' 00:00:00' : $validTo;
    DB::connection('sqlite-test')->table('bi_temporal_items')->insert([
        'id' => $id, 'text' => $text,
        'valid_from' => $vf, 'valid_to' => $vt,
        'known_from' => $knownFrom, 'known_to' => $knownTo,
    ]);
}

function allRows(int $id): Collection
{
    return DB::connection('sqlite-test')
        ->table('bi_temporal_items')
        ->where('id', $id)
        ->orderBy('known_from')
        ->get();
}

function openRows(int $id): Collection
{
    return DB::connection('sqlite-test')
        ->table('bi_temporal_items')
        ->where('id', $id)
        ->where('known_to', '9999-12-31 23:59:59')
        ->orderBy('valid_from')
        ->get();
}

// ─────────────────────────────────────────────────────────────────────────────
// Szenario 1 — Grafik 1: Normales Update (Adressänderung)
// ─────────────────────────────────────────────────────────────────────────────

it('Grafik 1: normal update creates left remainder and new record', function () {
    // Initial insert: Musterstrasse 10, valid from 2024-04-01
    $item = BiTemporalItem::create(['id' => 1, 'text' => 'Musterstrasse 10', 'valid_from' => '2024-04-01']);

    sleep(1);

    // Update: move to Maierstrasse 2 as of 2024-07-15
    $item->text = 'Maierstrasse 2';
    $item->valid_from = '2024-07-15';
    $item->save();

    $rows = allRows(1);
    expect($rows)->toHaveCount(3); // Rec1 TT-archived, Rec1' remainder, Rec2 new

    $open = openRows(1);
    expect($open)->toHaveCount(2); // Rec1' and Rec2

    // Rec1': old address for VT Apr–Jul14, TT-open
    $rec1prime = $open->first(fn ($r) => str_starts_with((string) $r->valid_from, '2024-04-01'));
    expect($rec1prime)->not->toBeNull()
        ->and(substr($rec1prime->valid_to, 0, 10))->toBe('2024-07-14')
        ->and($rec1prime->text)->toBe('Musterstrasse 10');

    // Rec2: new address from Jul15, TT-open
    $rec2 = $open->first(fn ($r) => str_starts_with((string) $r->valid_from, '2024-07-15'));
    expect($rec2)->not->toBeNull()
        ->and(substr($rec2->valid_to, 0, 10))->toBe('9999-12-31')
        ->and($rec2->text)->toBe('Maierstrasse 2');
});

it('Grafik 1: asOf(now, May) returns old address via remainder', function () {
    BiTemporalItem::create(['id' => 1, 'text' => 'Musterstrasse 10', 'valid_from' => '2024-04-01']);
    sleep(1);

    $item = BiTemporalItem::where('id', 1)->first();
    $item->text = 'Maierstrasse 2';
    $item->valid_from = '2024-07-15';
    $item->save();

    // asOf(vt) with TT defaulting to now(): what was valid on May 15 as known today?
    $result = BiTemporalItem::asOf('2024-05-15')->where('id', 1)->first();

    expect($result)->not->toBeNull()
        ->and($result->text)->toBe('Musterstrasse 10');
});

it('Grafik 1: currentVersion returns new address', function () {
    BiTemporalItem::create(['id' => 1, 'text' => 'Musterstrasse 10', 'valid_from' => '2024-04-01']);
    sleep(1);

    $item = BiTemporalItem::where('id', 1)->first();
    $item->text = 'Maierstrasse 2';
    $item->valid_from = '2024-07-15';
    $item->save();

    $current = BiTemporalItem::validAsOf(now())->where('id', 1)->first();
    expect($current->text)->toBe('Maierstrasse 2');
});

// ─────────────────────────────────────────────────────────────────────────────
// Szenario 2 — Grafik 2: Korrektur (gleicher valid_from → kein Remainder)
// ─────────────────────────────────────────────────────────────────────────────

it('Grafik 2: correction with same valid_from creates no left remainder', function () {
    // Start from Grafik 1 end-state: Rec1' and Rec2 open
    insertRaw(1, 'Musterstrasse 10', '2024-04-01', '2024-07-14', '2024-06-01 10:00:00', '9999-12-31 23:59:59');
    insertRaw(1, 'Maierstrasse 2', '2024-07-15', '9999-12-31', '2024-07-15 00:00:00', '9999-12-31 23:59:59');

    sleep(1);

    // Correct only the Jul15 VT period (target by valid_from to avoid affecting Rec1')
    BiTemporalItem::where('id', 1)
        ->where('valid_from', '2024-07-15 00:00:00')  // match stored 'Y-m-d 00:00:00' format
        ->update(['text' => 'Maierstrasse 4']);

    // Total: Rec1'(open) + Rec2(archived) + Rec3(open) = 3
    expect(allRows(1))->toHaveCount(3);
    expect(openRows(1))->toHaveCount(2); // Rec1' and Rec3

    $rec3 = openRows(1)->first(fn ($r) => str_starts_with((string) $r->valid_from, '2024-07-15'));
    expect($rec3->text)->toBe('Maierstrasse 4');
});

it('Grafik 2: TT-archive retains the wrong house number before correction', function () {
    insertRaw(1, 'Musterstrasse 10', '2024-04-01', '2024-07-14', '2024-06-01 10:00:00', '9999-12-31 23:59:59');
    insertRaw(1, 'Maierstrasse 2', '2024-07-15', '9999-12-31', '2024-07-15 00:00:00', '9999-12-31 23:59:59');

    sleep(1);

    $ttBeforeCorrection = now()->format('Y-m-d H:i:s');

    sleep(1);

    $item = BiTemporalItem::validAsOf('2024-07-15')->where('id', 1)->first();
    $item->text = 'Maierstrasse 4';
    $item->valid_from = '2024-07-15';
    $item->save();

    // asOf(vt, tt): what was known at tt=before-correction for vt=2024-07-15?
    $historical = BiTemporalItem::asOf('2024-07-15', $ttBeforeCorrection)->where('id', 1)->first();
    expect($historical->text)->toBe('Maierstrasse 2');
});

// ─────────────────────────────────────────────────────────────────────────────
// Szenario 3 — Grafik 3: Einschub zwischen bestehende Records (Discount)
// ─────────────────────────────────────────────────────────────────────────────

it('Grafik 3: discount insertion creates left and right remainders', function () {
    // Start with Rec2' (120, Jul15–Aug31) and Rec3 (130, Sep01–∞), both TT-open
    insertRaw(7, '120.00', '2024-07-15', '2024-08-31', '2024-07-15 00:00:00', '9999-12-31 23:59:59');
    insertRaw(7, '130.00', '2024-09-01', '9999-12-31', '2024-08-01 00:00:00', '9999-12-31 23:59:59');

    sleep(1);

    // Insert discount: 100.00 for Aug15–Sep14
    // allVersions() bypasses the global VT=today filter — required for modifying historical VT data
    BiTemporalItem::allVersions()->where('id', 7)->update([
        'text' => '100.00',
        'valid_from' => '2024-08-15',
        'valid_to' => '2024-09-14',
    ]);

    $open = openRows(7);
    // Rec2'' (Jul15–Aug14) + Rec4 (Aug15–Sep14 discount) + Rec3' (Sep15–∞) = 3 open records
    expect($open)->toHaveCount(3);

    $rec2double = $open->first(fn ($r) => str_starts_with((string) $r->valid_from, '2024-07-15'));
    expect($rec2double)->not->toBeNull()
        ->and(substr($rec2double->valid_to, 0, 10))->toBe('2024-08-14')
        ->and($rec2double->text)->toBe('120.00');

    $rec4 = $open->first(fn ($r) => str_starts_with((string) $r->valid_from, '2024-08-15'));
    expect($rec4)->not->toBeNull()
        ->and(substr($rec4->valid_to, 0, 10))->toBe('2024-09-14')
        ->and($rec4->text)->toBe('100.00');

    $rec3prime = $open->first(fn ($r) => str_starts_with((string) $r->valid_from, '2024-09-15'));
    expect($rec3prime)->not->toBeNull()
        ->and(substr($rec3prime->valid_to, 0, 10))->toBe('9999-12-31')
        ->and($rec3prime->text)->toBe('130.00');
});

it('Grafik 3: no gaps — every VT point returns exactly one open record', function () {
    insertRaw(7, '120.00', '2024-07-15', '2024-08-31', '2024-07-15 00:00:00', '9999-12-31 23:59:59');
    insertRaw(7, '130.00', '2024-09-01', '9999-12-31', '2024-08-01 00:00:00', '9999-12-31 23:59:59');

    sleep(1);

    BiTemporalItem::allVersions()->where('id', 7)->update([
        'text' => '100.00',
        'valid_from' => '2024-08-15',
        'valid_to' => '2024-09-14',
    ]);

    $testPoints = ['2024-07-20', '2024-08-14', '2024-08-15', '2024-09-14', '2024-09-15', '2025-01-01'];

    foreach ($testPoints as $vt) {
        $vtFull = $vt.' 00:00:00'; // VT values stored as 'Y-m-d 00:00:00'
        $count = DB::connection('sqlite-test')
            ->table('bi_temporal_items')
            ->where('id', 7)
            ->where('known_to', '9999-12-31 23:59:59')
            ->where('valid_from', '<=', $vtFull)
            ->where('valid_to', '>=', $vtFull)
            ->count();

        expect($count)->toBe(1, "Expected exactly 1 record for VT=$vt, got $count");
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Pure TT-versioning (no VT change)
// ─────────────────────────────────────────────────────────────────────────────

it('pure correction without valid_from creates one new TT-version with same VT', function () {
    BiTemporalItem::create(['id' => 1, 'text' => 'Wrong text', 'valid_from' => '2024-04-01']);
    sleep(1);

    $item = BiTemporalItem::where('id', 1)->first();
    $item->text = 'Correct text'; // no valid_from change
    $item->save();

    expect(allRows(1))->toHaveCount(2);

    $current = BiTemporalItem::where('id', 1)->first();
    expect($current->text)->toBe('Correct text')
        ->and($current->valid_from->format('Y-m-d'))->toBe('2024-04-01'); // VT unchanged
});
