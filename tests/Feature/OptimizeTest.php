<?php

use Illuminate\Support\Facades\Artisan;

afterEach(function () {
    Artisan::call('route:clear');
    Artisan::call('view:clear');
});

it('caches the Blade views after caching the routes in the same process, as optimize does', function () {
    expect(Artisan::call('route:cache'))->toBe(0)
        ->and(Artisan::call('view:cache'))->toBe(0);
});
