<?php

it('ships production-safe defaults in .env.example', function () {
    $contents = file_get_contents(__DIR__.'/../../.env.example');

    expect($contents)
        ->toMatch('/^APP_ENV=production$/m')
        ->toMatch('/^APP_DEBUG=false$/m')
        ->toMatch('/^SESSION_ENCRYPT=true$/m')
        ->toMatch('/^SESSION_SECURE_COOKIE=true$/m');
});
