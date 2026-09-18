<?php

use Illuminate\Support\Facades\File;

function cleanupPivotMigration(string $table): void
{
    foreach (File::glob(database_path("migrations/*_create_{$table}_table.php")) as $file) {
        File::delete($file);
    }
}

it('generates a uni-temporal pivot migration', function () {
    $this->artisan('temporal:make-pivot', [
        'table' => 'generated_pivot_probes',
        '--keys' => 'owner_id,target_id',
        '--soft-deletes' => true,
    ])->assertSuccessful();

    $files = File::glob(database_path('migrations/*_create_generated_pivot_probes_table.php'));

    expect($files)->toHaveCount(1);

    $content = File::get($files[0]);

    expect($content)->toContain('$table->unitemporal();')
        ->and($content)->toContain('$table->softDeletes();')
        ->and($content)->toContain("\$table->primary(['owner_id', 'target_id', 'known_from', 'known_to']);")
        ->and($content)->toContain("\$table->unique(['owner_id', 'target_id', 'known_to']);");

    cleanupPivotMigration('generated_pivot_probes');
});

it('generates a bi-temporal pivot migration with a surrogate id', function () {
    $this->artisan('temporal:make-pivot', [
        'table' => 'generated_bi_pivot',
        '--keys' => 'owner_id,target_id',
        '--bi-temporal' => true,
        '--surrogate-id' => true,
    ])->assertSuccessful();

    $files = File::glob(database_path('migrations/*_create_generated_bi_pivot_table.php'));

    expect($files)->toHaveCount(1);

    $content = File::get($files[0]);

    expect($content)->toContain('$table->bitemporal();')
        ->and($content)->toContain("\$table->primary(['id', 'valid_to', 'known_to']);")
        ->and($content)->toContain("\$table->unique(['owner_id', 'target_id', 'valid_to', 'known_to']);");

    cleanupPivotMigration('generated_bi_pivot');
});

it('fails without key columns', function () {
    $this->artisan('temporal:make-pivot', [
        'table' => 'generated_invalid',
    ])->assertFailed();
});
