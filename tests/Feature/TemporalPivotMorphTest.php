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

    Schema::create('morph_pivot_probes', function ($table) {
        $table->unsignedBigInteger('owner_id');
        $table->string('owner_type');
        $table->unsignedBigInteger('target_id');
        $table->unitemporal();
        $table->string('role')->nullable();
        $table->softDeletes();
        $table->primary(['owner_id', 'owner_type', 'target_id', 'known_from', 'known_to']);
        $table->unique(['owner_id', 'owner_type', 'target_id', 'known_to']);
    });
});

it('attaches, reads and soft deletes a temporal morph pivot', function () {
    $owner = PivotProbeOwner::create(['name' => 'o']);
    $target = PivotProbeTarget::create(['name' => 't']);

    $owner->morphTargets()->attach($target->id);

    expect($owner->morphTargets()->count())->toBe(1);

    $row = DB::table('morph_pivot_probes')->first();
    expect($row->owner_type)->toBe(PivotProbeOwner::class)
        ->and($row->known_to)->toBe('9999-12-31 23:59:59');

    expect($owner->morphTargets()->detach($target->id))->toBe(1);

    expect($owner->morphTargets()->count())->toBe(0)
        ->and(DB::table('morph_pivot_probes')
            ->where('known_to', '9999-12-31 23:59:59')
            ->whereNull('deleted_at')
            ->count())->toBe(0);
});

it('syncs a temporal morph pivot without version churn', function () {
    $owner = PivotProbeOwner::create(['name' => 'o']);
    $a = PivotProbeTarget::create(['name' => 'a']);
    $b = PivotProbeTarget::create(['name' => 'b']);

    $owner->morphTargets()->attach($a->id);
    $owner->morphTargets()->sync([$a->id]);
    expect(DB::table('morph_pivot_probes')->count())->toBe(1);

    $owner->morphTargets()->sync([$a->id, $b->id]);
    expect($owner->morphTargets()->count())->toBe(2);

    $owner->morphTargets()->sync([$b->id]);
    expect($owner->morphTargets()->count())->toBe(1);
});
