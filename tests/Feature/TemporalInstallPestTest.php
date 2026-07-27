<?php

use Illuminate\Support\Facades\Artisan;

it('installs the temporal connection into config/database.php', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'db_');
    unlink($tmp);
    copy(__DIR__.'/../Fixtures/database-original.php', $tmp);

    Artisan::call('temporal:install', ['--path' => $tmp]);

    $content = file_get_contents($tmp);

    expect($content)->toMatch("/'default'\s*=>\s*env\s*\(\s*'DB_CONNECTION'\s*,\s*'temporal'\s*\)/");
    expect($content)->toMatch("/'temporal'\s*=>\s*\[/");
    expect($content)->toMatch("/'base'\s*=>\s*'sqlite'/");
    expect($content)->toMatch("/'driver'\s*=>\s*'temporal-proxy'/");
    expect($content)->toMatch("/'column_from'\s*=>\s*'known_from'/");
    expect($content)->toMatch("/'column_to'\s*=>\s*'known_to'/");
    expect($content)->toMatch("/'max_timestamp'\s*=>\s*'9999-12-31 23:59:59'/");

    expect($content)->toMatch("/'sqlite'\s*=>\s*\[/");
    expect($content)->toMatch("/'mysql'\s*=>\s*\[/");
    expect($content)->toMatch("/'migrations'\s*=>\s*\[/");

    unlink($tmp);
});

it('uninstalls the temporal connection and restores the default', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'db_');
    unlink($tmp);
    copy(__DIR__.'/../Fixtures/database-original.php', $tmp);

    Artisan::call('temporal:install', ['--path' => $tmp]);
    Artisan::call('temporal:uninstall', ['--path' => $tmp]);

    $content = file_get_contents($tmp);

    expect($content)->not->toMatch("/'temporal'\s*=>\s*\[/");
    expect($content)->toMatch("/'default'\s*=>\s*env\s*\(\s*'DB_CONNECTION'\s*,\s*'sqlite'\s*\)/");

    expect($content)->toMatch("/'sqlite'\s*=>\s*\[/");
    expect($content)->toMatch("/'mysql'\s*=>\s*\[/");
    expect($content)->toMatch("/'migrations'\s*=>\s*\[/");

    unlink($tmp);
});

it('handles idempotent install', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'db_');
    unlink($tmp);
    copy(__DIR__.'/../Fixtures/database-original.php', $tmp);

    Artisan::call('temporal:install', ['--path' => $tmp]);
    $exitCode = Artisan::call('temporal:install', ['--path' => $tmp]);

    expect($exitCode)->toBe(0);

    $content = file_get_contents($tmp);

    expect($content)->toMatch("/'default'\s*=>\s*env\s*\(\s*'DB_CONNECTION'\s*,\s*'temporal'\s*\)/");
    expect($content)->toMatch("/'temporal'\s*=>\s*\[/");

    unlink($tmp);
});

it('handles idempotent uninstall', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'db_');
    unlink($tmp);
    copy(__DIR__.'/../Fixtures/database-original.php', $tmp);

    $exitCode = Artisan::call('temporal:uninstall', ['--path' => $tmp]);

    expect($exitCode)->toBe(0);

    $content = file_get_contents($tmp);

    expect($content)->not->toMatch("/'temporal'\s*=>\s*\[/");
    expect($content)->toMatch("/'default'\s*=>\s*env\s*\(\s*'DB_CONNECTION'\s*,\s*'sqlite'\s*\)/");

    unlink($tmp);
});
