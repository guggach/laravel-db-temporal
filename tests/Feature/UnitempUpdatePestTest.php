<?php

use Carbon\Carbon;
use Guggach\LaravelDbTemporal\Eloquent\UniTemporalScope;
use Guggach\LaravelDbTemporal\Tests\Models\UniTemporalId;

beforeEach(function () {
    // $this->artisan('migrate', [
    // // '--path' => './tests/migrations'
    // ])->run();
    RefreshDatabase::class;
});

it('insert a record and update one record', function () {

    $result = UniTemporalId::create([
        'text' => 'First Record',
    ]);

    $count = UniTemporalId::where('id', 1)->count();
    expect($count)->toBe(1);

    $now = new Carbon;
    $now = $now->format('Y-m-d H:i:s');

    $rec1 = UniTemporalId::where('id', 1)->where('known_from', '<=', $now)->where('known_to', '>=', $now)->first();
    expect($rec1->text)->toBe('First Record');

    $rec1->text = 'Second Record';
    $rec1->save();

    $count = UniTemporalId::withoutGlobalScope(UniTemporalScope::class)->where('id', 1)->count();
    expect($count)->toBe(2);

    $now = new Carbon;
    $now = $now->format('Y-m-d H:i:s');

    $rec2 = UniTemporalId::where('id', 1)->where('known_from', '<=', $now)->where('known_to', '>=', $now)->first();
    expect($rec2->text)->toBe('Second Record');

});

it('insert one record for Id 1 and 3 rec Id 2 -> count id 2 equals 3', function () {

    $recA = UniTemporalId::create([
        'text' => 'First Record',
    ]);
    $rec = UniTemporalId::create([
        'text' => 'First Record',
    ]);

    sleep(1);

    $now = new Carbon;
    $now = $now->format('Y-m-d H:i:s');

    $rec1 = UniTemporalId::where('id', 2)->where('known_from', '<=', $now)->where('known_to', '>=', $now)->first();
    expect($rec1->text)->toBe('First Record');

    $rec1->text = 'Second Record';
    $rec1->save();

    sleep(1);

    $now = new Carbon;
    $now = $now->format('Y-m-d H:i:s');

    $rec2 = UniTemporalId::where('id', 2)->where('known_from', '<=', $now)->where('known_to', '>=', $now)->first();
    expect($rec2->text)->toBe('Second Record');

    $rec2->text = 'Third Record';
    $rec2->save();

    sleep(1);

    $now = new Carbon;
    $now = $now->format('Y-m-d H:i:s');

    $rec3 = UniTemporalId::where('id', 2)->where('known_from', '<=', $now)->where('known_to', '>=', $now)->first();
    expect($rec3->text)->toBe('Third Record');

    $count = UniTemporalId::withoutGlobalScope(UniTemporalScope::class)->where('id', 2)->count();
    expect($count)->toBe(3);

});
