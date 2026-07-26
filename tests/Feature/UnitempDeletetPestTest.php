<?php

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Guggach\LaravelDbTemporal\Eloquent\UniTemporalScope;
use Guggach\LaravelDbTemporal\Tests\Models\UniTemporalId;

beforeEach(function(){
    // $this->artisan('migrate', [
    // // '--path' => './tests/migrations'
    // ])->run();
    RefreshDatabase::class;
});


it('insert a record, retrive model and delete it', function(){

    $result = UniTemporalId::create([
        'text' => 'First Record'
    ]);

    $count = UniTemporalId::where('id', 1)->count();
    expect($count)->toBe(1);

    $now = new Carbon();
    $now = $now->format('Y-m-d H:i:s');

    $rec1 = UniTemporalId::where('id', 1)->where('known_from', '<=', $now)->where('known_to', '>=', $now)->first();

    $rec1->text = 'Second Record';
    $rec1->save();

    $maxDateTime = '9999-12-31 23:59:59';

    $recBeforeDel = UniTemporalId::withoutGlobalScope(UniTemporalScope::class)->where('id', 1)->latest('known_to')->first();
    expect($recBeforeDel->known_to->format('Y-m-d H:i:s'))->toBe($maxDateTime);

    sleep(1);

    $recBeforeDel->delete();

    $recAfterDel = UniTemporalId::withoutGlobalScope(UniTemporalScope::class)->where('id', 1)->latest('known_to')->first();
    expect($recAfterDel->known_to->format('Y-m-d H:i:s'))->not->toBe($maxDateTime);




});


it('insert a record, retrive model and delete it and check with latestVersion scope', function(){

    $result = UniTemporalId::create([
        'text' => 'First Record'
    ]);

    $count = UniTemporalId::where('id', 1)->count();
    expect($count)->toBe(1);

    $now = new Carbon();
    $now = $now->format('Y-m-d H:i:s');

    $rec1 = UniTemporalId::where('id', 1)->where('known_from', '<=', $now)->where('known_to', '>=', $now)->first();

    $rec1->text = 'Second Record';
    $rec1->save();

    $maxDateTime = '9999-12-31 23:59:59';

    $recBeforeDel = UniTemporalId::where('id', 1)->latestVersion()->first();
    expect($recBeforeDel->known_to->format('Y-m-d H:i:s'))->toBe($maxDateTime);

    sleep(1);

    $recBeforeDel->delete();

    $recAfterDel = UniTemporalId::where('id', 1)->latestVersion()->first();
    expect($recAfterDel->known_to->format('Y-m-d H:i:s'))->not->toBe($maxDateTime);

});


