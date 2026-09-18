<?php

use Guggach\LaravelDbTemporal\Tests\Models\PivotProbeOwner;
use Guggach\LaravelDbTemporal\Tests\Models\PivotProbeTarget;
use Illuminate\Database\QueryException;
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
        $table->unique(['owner_id', 'target_id', 'known_to']);
    });

    Schema::create('uni_pivot_soft_probes', function ($table) {
        $table->unsignedBigInteger('owner_id');
        $table->unsignedBigInteger('target_id');
        $table->unitemporal();
        $table->string('role')->nullable();
        $table->softDeletes();
        $table->primary(['owner_id', 'target_id', 'known_from', 'known_to']);
        $table->unique(['owner_id', 'target_id', 'known_to']);
    });

    Schema::create('id_pivot_probes', function ($table) {
        $table->unsignedBigInteger('id');
        $table->unsignedBigInteger('owner_id');
        $table->unsignedBigInteger('target_id');
        $table->unitemporal();
        $table->string('role')->nullable();
        $table->softDeletes();
        $table->primary(['id', 'known_from', 'known_to']);
        $table->unique(['owner_id', 'target_id', 'known_to']);
    });
});

function pivotOwner(): PivotProbeOwner
{
    return PivotProbeOwner::create(['name' => 'owner']);
}

function pivotTarget(string $name = 'target'): PivotProbeTarget
{
    return PivotProbeTarget::create(['name' => $name]);
}

it('attach writes the temporal known columns', function () {
    $owner = pivotOwner();
    $target = pivotTarget();

    $owner->targets()->attach($target->id);

    $row = DB::table('uni_pivot_probes')->first();

    expect($row->known_to)->toBe('9999-12-31 23:59:59')
        ->and($row->known_from)->not->toBeNull();
});

it('reads only the current version and exposes pivot temporal columns', function () {
    $owner = pivotOwner();
    $target = pivotTarget();

    $owner->targets()->attach($target->id, ['role' => 'first']);

    expect($owner->targets()->count())->toBe(1)
        ->and($owner->targets()->withPivot('role')->first()->pivot->role)->toBe('first')
        ->and($owner->targets()->withPivot('role')->first()->pivot->known_to)->toBe('9999-12-31 23:59:59');

    $owner->targets()->updateExistingPivot($target->id, ['role' => 'second']);

    expect($owner->targets()->count())->toBe(1)
        ->and($owner->targets()->withPivot('role')->first()->pivot->role)->toBe('second')
        ->and(DB::table('uni_pivot_probes')->count())->toBe(2);
});

it('soft deletes on detach when a deleted_at column exists', function () {
    $owner = pivotOwner();
    $target = pivotTarget();

    $owner->softTargets()->attach($target->id);
    expect($owner->softTargets()->count())->toBe(1);

    expect($owner->softTargets()->detach($target->id))->toBe(1);

    expect($owner->softTargets()->count())->toBe(0)
        ->and(DB::table('uni_pivot_soft_probes')
            ->where('known_to', '9999-12-31 23:59:59')
            ->whereNull('deleted_at')
            ->count())->toBe(0)
        ->and(DB::table('uni_pivot_soft_probes')->whereNull('deleted_at')->count())->toBe(1)
        ->and(DB::table('uni_pivot_soft_probes')->count())->toBe(2);
});

it('closes known_to on detach without a soft delete column', function () {
    $owner = pivotOwner();
    $target = pivotTarget();

    $owner->targets()->attach($target->id);

    expect($owner->targets()->detach($target->id))->toBe(1)
        ->and($owner->targets()->count())->toBe(0)
        ->and(DB::table('uni_pivot_probes')->where('known_to', '9999-12-31 23:59:59')->count())->toBe(0)
        ->and(DB::table('uni_pivot_probes')->count())->toBe(1);
});

it('sync writes only diffs without version churn', function () {
    $owner = pivotOwner();
    $a = pivotTarget('a');
    $b = pivotTarget('b');

    $owner->targets()->attach($a->id);
    expect(DB::table('uni_pivot_probes')->count())->toBe(1);

    $owner->targets()->sync([$a->id]);
    expect(DB::table('uni_pivot_probes')->count())->toBe(1)
        ->and($owner->targets()->count())->toBe(1);

    $owner->targets()->sync([$a->id, $b->id]);
    expect($owner->targets()->count())->toBe(2);

    $owner->targets()->sync([$b->id]);
    expect($owner->targets()->count())->toBe(1)
        ->and($owner->targets()->whereKey($a->id)->exists())->toBeFalse();
});

it('assigns a surrogate id for an id-cluster pivot and soft deletes it', function () {
    $owner = pivotOwner();
    $a = pivotTarget('a');
    $b = pivotTarget('b');

    $owner->idTargets()->attach($a->id);
    $owner->idTargets()->attach($b->id);

    $ids = DB::table('id_pivot_probes')->where('known_to', '9999-12-31 23:59:59')
        ->orderBy('id')->pluck('id')->all();

    expect($ids)->toBe([1, 2])
        ->and($owner->idTargets()->count())->toBe(2);

    $owner->idTargets()->detach($a->id);

    expect($owner->idTargets()->count())->toBe(1)
        ->and(DB::table('id_pivot_probes')->where('known_to', '9999-12-31 23:59:59')->whereNull('deleted_at')->count())->toBe(1);
});

it('re-attaches a soft-deleted link without a unique violation', function () {
    $owner = pivotOwner();
    $target = pivotTarget();

    $owner->softTargets()->attach($target->id, ['role' => 'first']);
    $owner->softTargets()->detach($target->id);
    expect($owner->softTargets()->count())->toBe(0);

    $owner->softTargets()->attach($target->id, ['role' => 'again']);

    expect($owner->softTargets()->count())->toBe(1)
        ->and($owner->softTargets()->withPivot('role')->first()->pivot->role)->toBe('again')
        ->and(DB::table('uni_pivot_soft_probes')
            ->where('known_to', '9999-12-31 23:59:59')
            ->whereNull('deleted_at')
            ->count())->toBe(1);
});

it('re-attaches through sync after a soft delete', function () {
    $owner = pivotOwner();
    $target = pivotTarget();

    $owner->softTargets()->attach($target->id);
    $owner->softTargets()->sync([]);
    expect($owner->softTargets()->count())->toBe(0);

    $owner->softTargets()->sync([$target->id]);

    expect($owner->softTargets()->count())->toBe(1);
});

it('does not version updateExistingPivot when nothing changes', function () {
    $owner = pivotOwner();
    $target = pivotTarget();

    $owner->targets()->attach($target->id, ['role' => 'first']);
    expect(DB::table('uni_pivot_probes')->count())->toBe(1);

    $owner->targets()->updateExistingPivot($target->id, ['role' => 'first']);
    expect(DB::table('uni_pivot_probes')->count())->toBe(1);

    $owner->targets()->updateExistingPivot($target->id, ['role' => 'second']);
    expect(DB::table('uni_pivot_probes')->count())->toBe(2);
});

it('rejects a duplicate current attachment via unique constraint', function () {
    $owner = pivotOwner();
    $target = pivotTarget();

    $owner->targets()->attach($target->id);
    $owner->targets()->attach($target->id);
})->throws(QueryException::class);
