<?php

use Guggach\LaravelDbTemporal\Tests\Models\PivotProbeOwner;
use Guggach\LaravelDbTemporal\Tests\Models\PivotProbeTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('pivot_probe_owners', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });

    Schema::create('pivot_probe_targets', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });

    Schema::create('bi_pivot_probes', function ($table) {
        $table->unsignedBigInteger('owner_id');
        $table->unsignedBigInteger('target_id');
        $table->bitemporal();
        $table->string('role')->nullable();
        $table->softDeletes();
        $table->primary(['owner_id', 'target_id', 'valid_to', 'known_to']);
    });
});

function biOwner(): PivotProbeOwner
{
    return PivotProbeOwner::create(['name' => 'owner']);
}

function biTarget(string $name = 'target'): PivotProbeTarget
{
    return PivotProbeTarget::create(['name' => $name]);
}

it('attaches a bi-temporal pivot with valid-from today and open valid-to', function () {
    $owner = biOwner();
    $target = biTarget();

    $owner->biTargets()->attach($target->id);

    $row = DB::table('bi_pivot_probes')->first();

    expect($row->valid_from)->toStartWith(now()->toDateString())
        ->and($row->valid_to)->toStartWith('9999-12-31')
        ->and($row->known_to)->toBe('9999-12-31 23:59:59')
        ->and($owner->biTargets()->count())->toBe(1);
});

it('does not read future-dated links', function () {
    $owner = biOwner();
    $target = biTarget();

    $owner->biTargets()->attach($target->id, ['valid_from' => '2030-01-01']);

    expect($owner->biTargets()->count())->toBe(0);
});

it('closes the validity on detach', function () {
    $owner = biOwner();
    $target = biTarget();

    $owner->biTargets()->attach($target->id);
    expect($owner->biTargets()->count())->toBe(1);

    expect($owner->biTargets()->detach($target->id))->toBe(1);

    expect($owner->biTargets()->count())->toBe(0);

    // Detach wirkt sofort: valid_to liegt auf der letzten gültigen Grenze (gestern),
    // damit der inklusive Read-Filter (`valid_to >= heute`) den Link ausblendet.
    $current = DB::table('bi_pivot_probes')->where('known_to', '9999-12-31 23:59:59')->get();
    expect($current)->toHaveCount(1)
        ->and($current->first()->valid_to)->toBe(now()->subDay()->format('Y-m-d').' 00:00:00');
});

it('sync keeps only the current valid links', function () {
    $owner = biOwner();
    $a = biTarget('a');
    $b = biTarget('b');

    $owner->biTargets()->attach($a->id);
    expect($owner->biTargets()->count())->toBe(1);

    $owner->biTargets()->sync([$a->id, $b->id]);
    expect($owner->biTargets()->count())->toBe(2);

    $owner->biTargets()->sync([$b->id]);

    $remaining = $owner->biTargets()->get();

    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()->id)->toBe($b->id);
});

it('versions an attribute change and keeps the valid range', function () {
    $owner = biOwner();
    $target = biTarget();

    $owner->biTargets()->attach($target->id, ['role' => 'first']);
    $owner->biTargets()->updateExistingPivot($target->id, ['role' => 'second']);

    expect($owner->biTargets()->withPivot('role')->first()->pivot->role)->toBe('second')
        ->and(DB::table('bi_pivot_probes')->where('known_to', '9999-12-31 23:59:59')->count())->toBe(1)
        ->and(DB::table('bi_pivot_probes')->count())->toBe(2);
});

it('reads the valid state as of a date', function () {
    $owner = biOwner();
    $target = biTarget();

    $owner->biTargets()->attach($target->id, ['valid_from' => '2020-01-01']);
    expect($owner->biTargets()->count())->toBe(1);

    // Detach schliesst die Gültigkeit ab gestern (2026) → Link gültig [2020, 2026-09-17]
    $owner->biTargets()->detach($target->id);

    expect($owner->biTargets()->count())->toBe(0)
        ->and($owner->biTargets()->validAsOf('2021-06-01')->count())->toBe(1)
        ->and($owner->biTargets()->validAsOf('2019-01-01')->count())->toBe(0)
        ->and($owner->biTargets()->validAsOf('2030-01-01')->count())->toBe(0);
});
