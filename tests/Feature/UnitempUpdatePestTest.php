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
    $now = $now->format('Y-m-d H:i:s.u');

    $rec1 = UniTemporalId::where('id', 1)->where('known_from', '<=', $now)->where('known_to', '>=', $now)->first();
    expect($rec1->text)->toBe('First Record');

    $rec1->text = 'Second Record';
    $rec1->save();

    $count = UniTemporalId::withoutGlobalScope(UniTemporalScope::class)->where('id', 1)->count();
    expect($count)->toBe(2);

    $now = new Carbon;
    $now = $now->format('Y-m-d H:i:s.u');

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
    $now = $now->format('Y-m-d H:i:s.u');

    $rec1 = UniTemporalId::where('id', 2)->where('known_from', '<=', $now)->where('known_to', '>=', $now)->first();
    expect($rec1->text)->toBe('First Record');

    $rec1->text = 'Second Record';
    $rec1->save();

    sleep(1);

    $now = new Carbon;
    $now = $now->format('Y-m-d H:i:s.u');

    $rec2 = UniTemporalId::where('id', 2)->where('known_from', '<=', $now)->where('known_to', '>=', $now)->first();
    expect($rec2->text)->toBe('Second Record');

    $rec2->text = 'Third Record';
    $rec2->save();

    sleep(1);

    $now = new Carbon;
    $now = $now->format('Y-m-d H:i:s.u');

    $rec3 = UniTemporalId::where('id', 2)->where('known_from', '<=', $now)->where('known_to', '>=', $now)->first();
    expect($rec3->text)->toBe('Third Record');

    $count = UniTemporalId::withoutGlobalScope(UniTemporalScope::class)->where('id', 2)->count();
    expect($count)->toBe(3);

});

it('creates three distinct versions for three updates in the same second', function () {

    // Batch processes write faster than a second passes — thanks to
    // microseconds the PK triples (id, known_from, known_to) still stay
    // unique (previously: unique constraint violation).
    $rec = UniTemporalId::create(['text' => 'First Record']);
    $rec->text = 'Second Record';
    $rec->save();
    $rec->text = 'Third Record';
    $rec->save();
    $rec->text = 'Fourth Record';
    $rec->save();

    $versions = UniTemporalId::withoutGlobalScope(UniTemporalScope::class)
        ->where('id', $rec->id)
        ->orderBy('known_from')
        ->get();

    expect($versions)->toHaveCount(4)
        ->and($versions->map(fn ($v) => $v->id.'|'.$v->known_from->format('Y-m-d H:i:s.u').'|'.$v->known_to->format('Y-m-d H:i:s.u'))->unique()->count())->toBe(4);

    // The intervals are gapless: each version ends exactly 1 microsecond
    // before the next one starts.
    foreach ($versions as $index => $version) {
        if ($index === $versions->count() - 1) {
            expect($version->known_to->format('Y-m-d H:i:s.u'))->toBe('9999-12-31 23:59:59.000000');

            continue;
        }

        $next = $versions->get($index + 1);
        expect($version->known_from->lessThan($next->known_from))->toBeTrue()
            ->and($version->known_to->lessThan($next->known_from))->toBeTrue()
            ->and($version->known_from->lessThan($version->known_to))->toBeTrue();
    }

    expect(UniTemporalId::where('id', $rec->id)->first()->text)->toBe('Fourth Record');
});
