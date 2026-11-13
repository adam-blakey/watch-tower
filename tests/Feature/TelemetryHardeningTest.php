<?php

use App\Models\Project;
use App\Models\Trace;
use App\Watch\EventStore;
use App\Watch\Fingerprinter;

it('sends security headers on web responses', function () {
    $this->withoutVite();

    $this->get('/login')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('redacts credential headers from ingested requests', function () {
    $project = Project::factory()->create();

    (new EventStore(new Fingerprinter))->store($project, [
        'type' => 'request',
        'data' => [
            'request_id' => 'abc-123', 'method' => 'GET', 'uri' => '/x', 'status_code' => 200, 'duration_ms' => 1,
            'headers' => ['Authorization' => 'Bearer secret', 'cookie' => 'a=b', 'accept' => 'text/html'],
        ],
    ]);

    expect(Trace::where('correlation_id', 'abc-123')->first()->headers)
        ->toBe(['Authorization' => '[redacted]', 'cookie' => '[redacted]', 'accept' => 'text/html']);
});
