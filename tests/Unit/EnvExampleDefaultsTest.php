<?php

it('ships production-safe defaults in .env.example', function () {
    $env = parse_ini_file(__DIR__.'/../../.env.example', false, INI_SCANNER_RAW);

    expect($env['APP_ENV'])->toBe('production')
        ->and($env['APP_DEBUG'])->toBe('false')
        ->and($env['SESSION_ENCRYPT'])->toBe('true')
        ->and($env['SESSION_SECURE_COOKIE'])->toBe('true');
});
