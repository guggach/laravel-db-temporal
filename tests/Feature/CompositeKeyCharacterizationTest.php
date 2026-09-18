<?php

use Guggach\LaravelDbTemporal\Database\Query\UniTemporalBuilder;
use Guggach\LaravelDbTemporal\Eloquent\IsUniTemporal;
use Guggach\LaravelDbTemporal\Eloquent\UniTemporalModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Characterizing test: documents the current behaviour for a pure composite
 * key table [a_id, b_id, known_from, known_to] (NO id).
 *
 * Query builder: insert/update/delete (composite WHERE) work; insertGetId()
 * and delete($id) do not (single-key assumption).
 * Eloquent: no real composite key — performInsert generates a surrogate key
 * through getKeyName() (default "id").
 *
 * These tests pin the behaviour until the package supports composite pivots
 * (see docs/temp/pivot-relations-ticket.md).
 */
beforeEach(function () {
    Schema::create('composite_temporal_probes', function ($table) {
        $table->unsignedBigInteger('a_id');
        $table->unsignedBigInteger('b_id');
        $table->unitemporal();
        $table->string('role')->nullable();
        $table->primary(['a_id', 'b_id', 'known_from', 'known_to']);
    });
});

function compositeBuilder(): UniTemporalBuilder
{
    $connection = DB::connection();
    $builder = new UniTemporalBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
    $builder->from('composite_temporal_probes');

    return $builder;
}

function compositeModel(): Model
{
    return new class extends Model implements UniTemporalModel
    {
        use IsUniTemporal;

        protected $table = 'composite_temporal_probes';

        protected $guarded = [];

        public $timestamps = false;

        public $incrementing = false;
    };
}

it('probe 1: query builder insert() works with a composite key', function () {
    $ok = compositeBuilder()->insert(['a_id' => 1, 'b_id' => 2, 'role' => 'x']);

    $row = DB::table('composite_temporal_probes')->first();
    expect($ok)->toBeTrue()
        ->and($row->a_id)->toBe(1)
        ->and($row->known_to)->toBe('9999-12-31 23:59:59');
});

it('probe 2: query builder insertGetId() fails without an id column', function () {
    $this->expectException(Throwable::class);
    compositeBuilder()->insertGetId(['a_id' => 1, 'b_id' => 2]);
});

it('probe 3: query builder update() versions composite rows', function () {
    compositeBuilder()->insert(['a_id' => 1, 'b_id' => 2, 'role' => 'x']);

    $affected = compositeBuilder()->where('a_id', 1)->where('b_id', 2)->update(['role' => 'y']);

    $current = DB::table('composite_temporal_probes')->where('known_to', '9999-12-31 23:59:59')->get();
    expect($affected)->toBe(1)
        ->and($current)->toHaveCount(1)
        ->and($current->first()->role)->toBe('y')
        ->and(DB::table('composite_temporal_probes')->count())->toBe(2);
});

it('probe 4: query builder delete() with a composite where closes known_to', function () {
    compositeBuilder()->insert(['a_id' => 1, 'b_id' => 2, 'role' => 'x']);

    $affected = compositeBuilder()->where('a_id', 1)->where('b_id', 2)->delete();

    expect($affected)->toBe(1)
        ->and(DB::table('composite_temporal_probes')->where('known_to', '9999-12-31 23:59:59')->count())->toBe(0);
});

it('probe 5: query builder delete($id) fails without an id column', function () {
    $this->expectException(Throwable::class);
    compositeBuilder()->delete(1);
});

it('probe 6: max(id) without an id column (expects error or null)', function () {
    $result = compositeBuilder()->max('id');
    expect($result)->toBeNull();
});

it('probe 7: eloquent create() without an id column', function () {
    $this->expectException(Throwable::class);
    compositeModel()::create(['a_id' => 1, 'b_id' => 2, 'role' => 'x']);
});

it('probe 8: eloquent create() with primaryKey a_id set (workaround)', function () {
    $model = new class extends Model implements UniTemporalModel
    {
        use IsUniTemporal;

        protected $table = 'composite_temporal_probes';

        protected $guarded = [];

        protected $primaryKey = 'a_id';

        public $timestamps = false;

        public $incrementing = false;
    };

    // Is a_id overwritten / handled correctly?
    $model::create(['a_id' => 5, 'b_id' => 2, 'role' => 'x']);

    $row = DB::table('composite_temporal_probes')->first();
    expect($row->a_id)->toBe(5);
});
