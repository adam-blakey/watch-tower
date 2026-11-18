<?php

use App\Models\Environment;
use App\Models\ErrorOccurrence;
use App\Models\LogEntry;
use App\Models\Project;
use App\Models\Trace;
use App\Models\TraceQuery;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->project = Project::factory()->withApiKey('pk_test_production')->create();
    $this->staging = Environment::factory()->for($this->project)->withApiKey('pk_test_staging')->create(['name' => 'Staging', 'slug' => 'staging']);
});

/**
 * Exchange a key for a Nightwatch ingest token.
 */
function environmentToken(string $key): string
{
    return test()->withHeader('Authorization', 'Bearer '.$key)->postJson('/api/agent-auth')->assertOk()->json('token');
}

function nightwatchRequestFrom(string $token, string $traceId): TestResponse
{
    return postNightwatchRecords([[
        'v' => 1,
        't' => 'request',
        'timestamp' => now()->getTimestamp(),
        'trace_id' => $traceId,
        'method' => 'GET',
        'url' => 'https://app.test/',
        'status_code' => 200,
        'duration' => 1000,
    ]], $token);
}

it('stores events sent with an environment key under that environment', function () {
    $this->withHeader('Authorization', 'Bearer pk_test_staging')->postJson('/api/ingest', ['events' => [
        ['type' => 'query', 'data' => ['request_id' => 'req-1', 'sql' => 'select 1', 'duration_ms' => 2]],
        ['type' => 'request', 'data' => ['request_id' => 'req-1', 'method' => 'GET', 'uri' => '/']],
        ['type' => 'exception', 'data' => ['request_id' => 'req-1', 'class' => 'RuntimeException', 'message' => 'Boom']],
        ['type' => 'log', 'data' => ['request_id' => 'req-1', 'level' => 'error', 'message' => 'Boom']],
    ]])->assertStatus(202);

    expect(Trace::sole()->environment_id)->toBe($this->staging->id)
        ->and(TraceQuery::sole()->environment_id)->toBe($this->staging->id)
        ->and(ErrorOccurrence::sole()->environment_id)->toBe($this->staging->id)
        ->and(LogEntry::sole()->environment_id)->toBe($this->staging->id);
});

it('stores events sent through a Nightwatch token under the environment of the key', function () {
    nightwatchRequestFrom(environmentToken('pk_test_staging'), 'trace-staging')->assertOk();
    nightwatchRequestFrom(environmentToken('pk_test_production'), 'trace-production')->assertOk();

    expect(Trace::where('correlation_id', 'trace-staging')->value('environment_id'))->toBe($this->staging->id)
        ->and(Trace::where('correlation_id', 'trace-production')->value('environment_id'))->toBe($this->project->defaultEnvironment->id)
        ->and(Trace::pluck('project_id')->unique()->all())->toBe([$this->project->id]);
});

it('records the environment in the agent token', function () {
    $payload = json_decode(Crypt::decryptString(environmentToken('pk_test_staging')), true);

    expect($payload['environment_id'])->toBe($this->staging->id)
        ->and($payload['project_id'])->toBe($this->project->id);
});

it('treats a token without an environment as one for the default environment', function () {
    $legacyToken = ingestToken($this->project->id);

    nightwatchRequestFrom($legacyToken, 'trace-legacy')->assertOk();

    expect(Trace::sole()->environment_id)->toBe($this->project->defaultEnvironment->id);

    $this->staging->rotateApiKey();
    nightwatchRequestFrom($legacyToken, 'trace-after-staging-rotation')->assertOk();

    $this->project->defaultEnvironment->rotateApiKey();
    nightwatchRequestFrom($legacyToken, 'trace-after-default-rotation')
        ->assertStatus(401)
        ->assertJson(['message' => 'Revoked ingest token']);
});

it('rejects a token for an environment of another project', function () {
    $other = Project::factory()->create();
    $token = Crypt::encryptString(json_encode([
        'project_id' => $this->project->id,
        'environment_id' => $other->defaultEnvironment->id,
        'expires_at' => time() + 3600,
        'key_version' => 1,
    ]));

    nightwatchRequestFrom($token, 'trace-1')
        ->assertStatus(401)
        ->assertJson(['message' => 'Unknown environment']);
});

it('rotates the key of one environment without revoking the keys and tokens of another', function () {
    $productionToken = environmentToken('pk_test_production');
    $stagingToken = environmentToken('pk_test_staging');

    $newStagingKey = $this->staging->rotateApiKey();

    $this->withHeader('Authorization', 'Bearer pk_test_staging')->postJson('/api/agent-auth')->assertUnauthorized();
    nightwatchRequestFrom($stagingToken, 'trace-1')->assertUnauthorized();

    $this->withHeader('Authorization', 'Bearer '.$newStagingKey)->postJson('/api/agent-auth')->assertOk();
    $this->withHeader('Authorization', 'Bearer pk_test_production')->postJson('/api/agent-auth')->assertOk();
    nightwatchRequestFrom($productionToken, 'trace-2')->assertOk();

    expect($this->project->defaultEnvironment->fresh()->api_key_version)->toBe(1)
        ->and($this->staging->fresh()->api_key_version)->toBe(2);
});

it('gives the default environment a grace period of its own', function () {
    $newProductionKey = $this->project->defaultEnvironment->rotateApiKey(gracePeriodSeconds: 3600);

    $this->withHeader('Authorization', 'Bearer pk_test_production')->postJson('/api/agent-auth')->assertOk();
    $this->withHeader('Authorization', 'Bearer '.$newProductionKey)->postJson('/api/agent-auth')->assertOk();

    $this->travel(61)->minutes();

    $this->withHeader('Authorization', 'Bearer pk_test_production')->postJson('/api/agent-auth')->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer pk_test_staging')->postJson('/api/agent-auth')->assertOk();
});
