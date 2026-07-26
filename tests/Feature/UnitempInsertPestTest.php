<?php

use Guggach\LaravelDbTemporal\Eloquent\IsUniTemporal;
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

    class uniTemporalIdBelives extends Model
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

    // $model = new UniTemporalId();

    $colFrom = config('db-temporal.defaults.columnTrxDateFrom');
    $colTo = config('db-temporal.defaults.columnTrxDateTo');
    config()->set('db-temporal.defaults.columnTrxDateFrom', null);
    config()->set('db-temporal.defaults.columnTrxDateTo', null);

    class uniTemporalIdTrxDates extends Model
    {
        use IsUniTemporal;

        protected $guarded = [];
    }

    $result = uniTemporalIdTrxDates::create([
        'text' => 'First Record',
    ]);

    $dbResult = uniTemporalIdTrxDates::find(1)->trx_date_from;
    expect($result->trx_date_from)->toEqual($dbResult);

    $this->assertDatabaseHas('uni_temporal_id_trx_dates', ['trx_date_from' => $result->trx_date_from, 'trx_date_to' => $result->trx_date_to, 'id' => 1]);

    config()->set('db-temporal.defaults.columnTrxDateFrom', $colFrom);
    config()->set('db-temporal.defaults.columnTrxDateTo', $colTo);

});

it('Table with Ulid key instead of autoincrement key and insert two records', function () {

    // $model = new UniTemporalId();

    class UniTemporalUlids extends Model
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
