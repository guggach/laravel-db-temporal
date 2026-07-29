<?php

use Guggach\LaravelDbTemporal\Eloquent\IsUniTemporal;
use Guggach\LaravelDbTemporal\Eloquent\UniTemporalModel;
use Guggach\LaravelDbTemporal\Tests\Models\UniTemporalId;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

beforeAll(function () {
    // $this->artisan('migrate', [
    // // '--path' => './tests/migrations'
    // ])->run();
    RefreshDatabase::class;
});

it('insertGetId returns 1 for the first record using the builder directly', function () {
    $builder = (new UniTemporalId)->newModelQuery()->getQuery();

    $id = $builder->insertGetId(['text' => 'First Record']);

    expect($id)->toBe(1);
    $this->assertDatabaseHas('uni_temporal_ids', ['id' => 1, 'text' => 'First Record']);
});

it('insertGetId auto-increments correctly for subsequent inserts', function () {
    $builder = (new UniTemporalId)->newModelQuery()->getQuery();

    $id1 = $builder->insertGetId(['text' => 'First']);
    $id2 = $builder->insertGetId(['text' => 'Second']);

    expect($id1)->toBe(1);
    expect($id2)->toBe(2);
    $this->assertDatabaseHas('uni_temporal_ids', ['id' => 1, 'text' => 'First']);
    $this->assertDatabaseHas('uni_temporal_ids', ['id' => 2, 'text' => 'Second']);
});

it('Insert first Record with standard id', function () {
    $result = UniTemporalId::create([
        'text' => 'First Record',
    ]);

    $dbResult = UniTemporalId::find(1)->known_from;
    expect($result->known_from)->toEqual($dbResult);

    $this->assertDatabaseHas('uni_temporal_ids', ['known_from' => $result->known_from, 'known_to' => $result->known_to, 'id' => 1]);

});

it('Insert a second Record with standard id', function () {
    $result = UniTemporalId::create([
        'text' => 'First Record',
    ]);

    $result = UniTemporalId::create([
        'text' => 'Second Record',
    ]);

    $dbResult = UniTemporalId::find(2)->known_from;
    expect($result->known_from)->toEqual($dbResult);

    $this->assertDatabaseHas('uni_temporal_ids', ['known_from' => $result->known_from, 'known_to' => $result->known_to, 'id' => 2]);

});

it('Override Trx column names in Model and insert one record', function () {

    // $model = new UniTemporalId();

    class uniTemporalIdBelives extends Model implements UniTemporalModel
    {
        use IsUniTemporal;

        protected $guarded = [];

        const COLUMN_TRX_DATE_FROM = 'belive_from';

        const COLUMN_TRX_DATE_TO = 'belive_until';
    }

    $result = uniTemporalIdBelives::create([
        'text' => 'First Record',
    ]);

    $dbResult = uniTemporalIdBelives::find(1)->belive_from;
    expect($result->belive_from)->toEqual($dbResult);

    $this->assertDatabaseHas('uni_temporal_id_belives', ['belive_from' => $result->belive_from, 'belive_until' => $result->belive_until, 'id' => 1]);

});

it('Use absolute default column names not configured in config nor model and insert one record', function () {

    class uniTemporalIdTrxDates extends Model implements UniTemporalModel
    {
        use IsUniTemporal;

        protected $guarded = [];
    }

    $result = uniTemporalIdTrxDates::create([
        'text' => 'First Record',
    ]);

    $dbResult = uniTemporalIdTrxDates::find(1)->known_from;
    expect($result->known_from)->toEqual($dbResult);

    $this->assertDatabaseHas('uni_temporal_id_trx_dates', ['known_from' => $result->known_from, 'known_to' => $result->known_to, 'id' => 1]);

});

it('insert first record with model using $incrementing = false (non-ULID)', function () {
    class UniTemporalNonIncrementing extends Model implements UniTemporalModel
    {
        use IsUniTemporal;

        protected $table = 'uni_temporal_ids';

        public $incrementing = false;

        protected $guarded = [];
    }

    $result = UniTemporalNonIncrementing::create([
        'text' => 'First Record',
    ]);

    expect($result->id)->toBe(1);
    $this->assertDatabaseHas('uni_temporal_ids', ['id' => 1, 'text' => 'First Record']);
});

it('auto-increments correctly with model using $incrementing = false', function () {
    class UniTemporalNonInc extends Model implements UniTemporalModel
    {
        use IsUniTemporal;

        protected $table = 'uni_temporal_ids';

        public $incrementing = false;

        protected $guarded = [];
    }

    $first = UniTemporalNonInc::create(['text' => 'First']);
    $second = UniTemporalNonInc::create(['text' => 'Second']);

    expect($first->id)->toBe(1);
    expect($second->id)->toBe(2);
    $this->assertDatabaseHas('uni_temporal_ids', ['id' => 1, 'text' => 'First']);
    $this->assertDatabaseHas('uni_temporal_ids', ['id' => 2, 'text' => 'Second']);
});

it('Table with Ulid key instead of autoincrement key and insert two records', function () {

    // $model = new UniTemporalId();

    class UniTemporalUlids extends Model implements UniTemporalModel
    {
        use HasUlids;
        use IsUniTemporal;

        protected $guarded = [];
    }

    $result = UniTemporalUlids::create([
        'text' => 'First Record',
    ]);

    $id = $result->id;
    $dbResult = UniTemporalUlids::find($id)->known_from;
    expect($result->known_from)->toEqual($dbResult);

    $this->assertDatabaseHas('uni_temporal_ulids', ['known_from' => $result->known_from, 'known_to' => $result->known_to, 'id' => $id]);

    $result = UniTemporalUlids::create([
        'text' => 'Second Record',
    ]);

    $id = $result->id;
    $dbResult = UniTemporalUlids::find($id)->known_from;
    expect($result->known_from)->toEqual($dbResult);

    $this->assertDatabaseHas('uni_temporal_ulids', ['known_from' => $result->known_from, 'known_to' => $result->known_to, 'id' => $id]);

});
