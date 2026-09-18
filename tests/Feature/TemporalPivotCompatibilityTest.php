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

    Schema::create('uni_pivot_probes', function ($table) {
        $table->unsignedBigInteger('owner_id');
        $table->unsignedBigInteger('target_id');
        $table->unitemporal();
        $table->string('role')->nullable();
        $table->primary(['owner_id', 'target_id', 'known_from', 'known_to']);
    });

    Schema::create('plain_pivot_probes', function ($table) {
        $table->unsignedBigInteger('owner_id');
        $table->unsignedBigInteger('target_id');
        $table->string('role')->nullable();
        $table->primary(['owner_id', 'target_id']);
    });
});

it('leaves a non-temporal pivot untouched', function () {
    $owner = PivotProbeOwner::create(['name' => 'o']);
    $target = PivotProbeTarget::create(['name' => 't']);

    $owner->plainTargets()->attach($target->id, ['role' => 'r']);

    expect($owner->plainTargets()->count())->toBe(1)
        ->and($owner->plainTargets()->withPivot('role')->first()->pivot->role)->toBe('r')
        ->and(DB::table('plain_pivot_probes')->count())->toBe(1);

    $owner->plainTargets()->detach($target->id);

    expect(DB::table('plain_pivot_probes')->count())->toBe(0);
});

it('detects a temporal pivot via the using marker trait', function () {
    $owner = PivotProbeOwner::create(['name' => 'o']);
    $target = PivotProbeTarget::create(['name' => 't']);

    $owner->markedTargets()->attach($target->id);

    expect($owner->markedTargets()->count())->toBe(1);

    $row = DB::table('uni_pivot_probes')->first();
    expect($row->known_to)->toBe('9999-12-31 23:59:59');

    $owner->markedTargets()->detach($target->id);

    expect($owner->markedTargets()->count())->toBe(0)
        ->and(DB::table('uni_pivot_probes')->count())->toBe(1);
});
