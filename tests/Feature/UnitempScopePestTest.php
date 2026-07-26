<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;
use Guggach\LaravelDbTemporal\Eloquent\UniTemporalScope;
use Guggach\LaravelDbTemporal\Tests\Models\UniTemporalId;
use Guggach\LaravelDbTemporal\tests\database\UniTemporalIdSeeder;

beforeEach(function(){
    // $this->artisan('migrate', [
    // // '--path' => './tests/migrations'
    // ])->run();
    RefreshDatabase::class;

 //   $this->seed(UnitTemporalIdSeeder::class);
});

function seedDB() {
    $t = 'uni_temporal_ids';

    $records = [
        0 => [
            'id' => 1,
            'known_from' => '2023-05-01 00:00:00',
            'known_to' => '2023-05-05 16:59:59',
            'text' => 'first record'
        ],
        1 => [
            'id' => 1,
            'known_from' => '2023-05-05 17:00:00',
            'known_to' => '2023-05-10 16:59:59',
            'text' => 'second record'
        ],
        2 => [
            'id' => 1,
            'known_from' => '2023-05-10 17:00:00',
            'known_to' => '2023-05-15 16:59:59',
            'text' => 'third record'
        ],
        3 => [
            'id' => 1,
            'known_from' => '2023-05-15 17:00:00',
            'known_to' => '9999-12-31 23:59:59',
            'text' => 'fourth record'
        ],
    ];

    foreach($records as $record){
        DB::connection('sqlite-test')->table($t)->insert($record);
    }

}

it('test db seed', function() {


    $this->artisan('db:seed', ['--class' => 'Guggach\LaravelDbTemporal\Tests\database\UniTemporalIdSeeder']);

 //   $this->seed(UniTemporalIdSeeder::class);

    $recs = UniTemporalId::withoutGlobalScope(UniTemporalScope::class)->count();
    //dump($recs);
    expect($recs)->toBe(4);

    $a = UniTemporalId::where('id', 1)->get();
    // dump($a->first());
    expect($a->first()->text)->toBe('fourth record');

    $b = UniTemporalId::where('id', 1)->firstVersion()->first();
    // dump($b);
    expect($b->text)->toBe('first record');

    $c = UniTemporalId::where('id', 1)->versionAsOf('2023-05-08 00:00:00')->get();
    //dump($c);
    expect($c->count())->toBe(1);
    expect($c->first()->text)->toBe('second record');

    $d = UniTemporalId::where('id', 1)->allVersions()->get();
    //dump($d);
    expect($d->count())->toBe(4);

    $e = UniTemporalId::where('id', 1)->versionsInRange('2023-05-08', '2023-05-17')->get();
    //dump($e);
    expect($e->count())->toBe(1);
    expect($e->first()->text)->toBe('third record');

    $f = UniTemporalId::where('id', 1)->versionsTouchedRange('2023-05-08', '2023-05-17')->get();
    //dump($f);
    expect($f->count())->toBe(3);
    expect($f->first()->text)->toBe('second record');
    expect($f->last()->text)->toBe('fourth record');



});



// it('insert a record, retrive model and delete it', function(){

//     $result = UniTemporalId::create([
//         'text' => 'First Record'
//     ]);

//     $count = UniTemporalId::where('id', 1)->count();
//     expect($count)->toBe(1);

//     $now = new Carbon();
//     $now = $now->format('Y-m-d H:i:s');

//     $rec1 = UniTemporalId::where('id', 1)->where('known_from', '<=', $now)->where('known_to', '>=', $now)->first();

//     $rec1->text = 'Second Record';
//     $rec1->save();

//     // $recBeforeDel = UniTemporalId::where('id', 1)->where('known_to', '=', function(Builder $q) {
//     //    // $q->from('uni_temporal_ids')->where('id', 1)->max('known_to');
//     //    UniTemporalId::where('id', 1)->max('known_to')->first();
//     // })->first();
//     $maxDateTime = '9999-12-31 23:59:59';

//     $recBeforeDel = UniTemporalId::where('id', 1)->latest('known_to')->first();
//     expect($recBeforeDel->known_to->format('Y-m-d H:i:s'))->toBe($maxDateTime);

//     sleep(1);

//     $recBeforeDel->delete();

//     $recAfterDel = UniTemporalId::where('id', 1)->latest('known_to')->first();
//     expect($recAfterDel->known_to->format('Y-m-d H:i:s'))->not->toBe($maxDateTime);




// });


