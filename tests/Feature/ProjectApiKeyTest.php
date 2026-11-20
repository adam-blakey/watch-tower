<?php

use App\Models\Environment;
use App\Models\Project;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Telescope\Telescope;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
});

/**
 * Present a key to one of the endpoints that accept it and return the HTTP status.
 *
 * The Nightwatch ingest endpoint takes no key, only the token that /api/agent-auth
 * issues for one, so the key is exchanged first and a rejected key ends there.
 */
function presentApiKey(string $surface, string $key): int
{
    $ingestBody = ['events' => [['type' => 'request', 'data' => ['request_id' => 'req-1', 'method' => 'GET', 'uri' => '/users']]]];

    return match ($surface) {
        'ingest' => test()->withHeader('Authorization', 'Bearer '.$key)->postJson('/api/ingest', $ingestBody)->status(),
        'ingest via X-API-Key' => test()->withHeader('X-API-Key', $key)->postJson('/api/ingest', $ingestBody)->status(),
        'agent-auth' => agentAuth($key)->status(),
        'nightwatch-ingest' => ($auth = agentAuth($key))->status() === 200
            ? ingestWithToken($auth->json('token'))->status()
            : $auth->status(),
    };
}

function agentAuth(string $key): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.$key)->postJson('/api/agent-auth');
}

function ingestWithToken(string $token): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/nightwatch-ingest', ['records' => []]);
}

function issuedToken(string $key): string
{
    return agentAuth($key)->assertOk()->json('token');
}

dataset('key surfaces', ['ingest', 'ingest via X-API-Key', 'agent-auth', 'nightwatch-ingest']);

it('accepts the current key', function (string $surface) {
    Project::factory()->withApiKey('pk_test_current')->create();

    expect(presentApiKey($surface, 'pk_test_current'))->toBeIn([200, 202]);
})->with('key surfaces');

it('rejects an unknown key', function (string $surface) {
    Project::factory()->withApiKey('pk_test_current')->create();

    expect(presentApiKey($surface, 'pk_test_unknown'))->toBe(401);
})->with('key surfaces');

it('accepts the new key and the previous key during the grace period', function (string $surface) {
    $environment = Project::factory()->withApiKey('pk_test_old')->create()->environments()->sole();
    $newKey = $environment->rotateApiKey(gracePeriodSeconds: 3600);

    expect(presentApiKey($surface, 'pk_test_old'))->toBeIn([200, 202])
        ->and(presentApiKey($surface, $newKey))->toBeIn([200, 202]);
})->with('key surfaces');

it('rejects the previous key once the grace period has ended', function (string $surface) {
    $environment = Project::factory()->withApiKey('pk_test_old')->create()->environments()->sole();
    $newKey = $environment->rotateApiKey(gracePeriodSeconds: 3600);

    $this->travel(61)->minutes();

    expect(presentApiKey($surface, 'pk_test_old'))->toBe(401)
        ->and(presentApiKey($surface, $newKey))->toBeIn([200, 202]);
})->with('key surfaces');

it('rejects the previous key at once after a rotation without a grace period', function (string $surface) {
    $environment = Project::factory()->withApiKey('pk_test_old')->create()->environments()->sole();
    $newKey = $environment->rotateApiKey();

    expect(presentApiKey($surface, 'pk_test_old'))->toBe(401)
        ->and(presentApiKey($surface, $newKey))->toBeIn([200, 202]);
})->with('key surfaces');

it('only keeps the key that was current before the latest rotation', function () {
    $environment = Project::factory()->withApiKey('pk_test_first')->create()->environments()->sole();
    $second = $environment->rotateApiKey(gracePeriodSeconds: 3600);
    $third = $environment->rotateApiKey(gracePeriodSeconds: 3600);

    expect(presentApiKey('agent-auth', 'pk_test_first'))->toBe(401)
        ->and(presentApiKey('agent-auth', $second))->toBe(200)
        ->and(presentApiKey('agent-auth', $third))->toBe(200)
        ->and($environment->fresh()->api_key_version)->toBe(3);
});

it('does not authenticate a project that is being deleted', function () {
    $project = Project::factory()->withApiKey('pk_test_current')->create();
    $project->forceFill(['deleting_at' => now()])->save();

    expect(Environment::findByApiKey('pk_test_current'))->toBeNull()
        ->and(presentApiKey('ingest', 'pk_test_current'))->toBe(401);
});

it('generates a prefixed key and stores only its hash and display prefix', function () {
    $environment = Project::factory()->create()->environments()->sole();
    $key = $environment->rotateApiKey();

    $row = (array) DB::table('environments')->where('id', $environment->id)->first();

    expect($key)->toStartWith('pk_')->and(strlen($key))->toBeGreaterThanOrEqual(35)
        ->and($row['api_key_hash'])->toBe(hash('sha256', $key))
        ->and($row['api_key_prefix'])->toBe(substr($key, 0, 10));

    expect(json_encode($row))->not->toContain($key);
});

it('moves the current hash to the previous slot with an expiry, and clears it without a grace period', function () {
    $environment = Project::factory()->withApiKey('pk_test_old')->create()->environments()->sole();

    $environment->rotateApiKey(gracePeriodSeconds: 7200);
    $environment->refresh();

    expect($environment->previous_api_key_hash)->toBe(hash('sha256', 'pk_test_old'))
        ->and($environment->previous_api_key_expires_at->equalTo(now()->addHours(2)))->toBeTrue()
        ->and($environment->api_key_version)->toBe(2)
        ->and($environment->hasActivePreviousApiKey())->toBeTrue();

    $environment->rotateApiKey();
    $environment->refresh();

    expect($environment->previous_api_key_hash)->toBeNull()
        ->and($environment->previous_api_key_expires_at)->toBeNull()
        ->and($environment->api_key_version)->toBe(3)
        ->and($environment->hasActivePreviousApiKey())->toBeFalse();
});

it('keeps the key hashes out of serialized environments', function () {
    $environment = Project::factory()->create()->environments()->sole();
    $environment->rotateApiKey(gracePeriodSeconds: 3600);

    expect($environment->fresh()->toArray())
        ->not->toHaveKeys(['api_key_hash', 'previous_api_key_hash'])
        ->toHaveKey('api_key_prefix');
});

it('records the key version in the agent token', function () {
    $environment = Project::factory()->withApiKey('pk_test_old')->create()->environments()->sole();

    $payload = fn (string $token) => json_decode(Crypt::decryptString($token), true);

    expect($payload(issuedToken('pk_test_old'))['key_version'])->toBe(1);

    $newKey = $environment->rotateApiKey(gracePeriodSeconds: 3600);

    expect($payload(issuedToken($newKey))['key_version'])->toBe(2)
        ->and($payload(issuedToken('pk_test_old'))['key_version'])->toBe(1);
});

it('rejects a token issued before a rotation without a grace period', function () {
    $environment = Project::factory()->withApiKey('pk_test_old')->create()->environments()->sole();
    $token = issuedToken('pk_test_old');

    ingestWithToken($token)->assertOk();

    $environment->rotateApiKey();

    ingestWithToken($token)
        ->assertStatus(401)
        ->assertJson(['message' => 'Revoked ingest token', 'stop' => true]);
});

it('keeps a token issued before a rotation only for the grace period', function () {
    $environment = Project::factory()->withApiKey('pk_test_old')->create()->environments()->sole();
    $token = issuedToken('pk_test_old');

    $environment->rotateApiKey(gracePeriodSeconds: 3600);

    ingestWithToken($token)->assertOk();

    $this->travel(61)->minutes();

    ingestWithToken($token)->assertStatus(401);
});

it('keeps a token issued with the previous key only for the grace period', function () {
    $environment = Project::factory()->withApiKey('pk_test_old')->create()->environments()->sole();
    $environment->rotateApiKey(gracePeriodSeconds: 3600);
    $token = issuedToken('pk_test_old');

    ingestWithToken($token)->assertOk();

    $this->travel(61)->minutes();

    ingestWithToken($token)->assertStatus(401);
});

it('keeps a token issued with the new key valid after the grace period', function () {
    $environment = Project::factory()->withApiKey('pk_test_old')->create()->environments()->sole();
    $newKey = $environment->rotateApiKey(gracePeriodSeconds: 3600);
    $token = issuedToken($newKey);

    $this->travel(61)->minutes();

    ingestWithToken($token)->assertOk();
});

it('revokes tokens from two rotations ago even while the latest grace period runs', function () {
    $environment = Project::factory()->withApiKey('pk_test_first')->create()->environments()->sole();
    $token = issuedToken('pk_test_first');

    $second = $environment->rotateApiKey(gracePeriodSeconds: 3600);
    $secondToken = issuedToken($second);
    $environment->rotateApiKey(gracePeriodSeconds: 3600);

    ingestWithToken($token)->assertStatus(401);
    ingestWithToken($secondToken)->assertOk();
});

it('accepts a token without a key version only while the key has never been rotated', function () {
    $environment = Project::factory()->create()->environments()->sole();
    $legacyToken = Crypt::encryptString(json_encode([
        'project_id' => $environment->project_id,
        'expires_at' => time() + 3600,
    ]));

    ingestWithToken($legacyToken)->assertOk();

    $environment->rotateApiKey(gracePeriodSeconds: 3600);

    ingestWithToken($legacyToken)->assertStatus(401);
});

it('stamps the last use of a key and does not rewrite it on every request', function () {
    $environment = Project::factory()->withApiKey('pk_test_current')->create()->environments()->sole();
    $createdAt = $environment->fresh()->updated_at;

    expect($environment->fresh()->api_key_last_used_at)->toBeNull();

    $firstUse = now();
    presentApiKey('ingest', 'pk_test_current');

    expect($environment->fresh()->api_key_last_used_at->equalTo($firstUse))->toBeTrue();

    $this->travel(4)->minutes();
    DB::enableQueryLog();
    presentApiKey('agent-auth', 'pk_test_current');
    presentApiKey('ingest', 'pk_test_current');
    $writes = collect(DB::getQueryLog())->filter(fn (array $query) => str_starts_with($query['query'], 'update'));
    DB::disableQueryLog();

    expect($writes)->toBeEmpty()
        ->and($environment->fresh()->api_key_last_used_at->equalTo($firstUse))->toBeTrue();

    $this->travel(2)->minutes();
    $laterUse = now();
    presentApiKey('agent-auth', 'pk_test_current');

    expect($environment->fresh()->api_key_last_used_at->equalTo($laterUse))->toBeTrue()
        ->and($environment->fresh()->updated_at->equalTo($createdAt))->toBeTrue();
});

it('counts a use of the previous key too', function () {
    $environment = Project::factory()->withApiKey('pk_test_old')->create()->environments()->sole();
    $environment->rotateApiKey(gracePeriodSeconds: 3600);

    presentApiKey('ingest', 'pk_test_old');

    expect($environment->fresh()->api_key_last_used_at)->not->toBeNull();
});

it('does not stamp a key use for a rejected key', function () {
    $environment = Project::factory()->withApiKey('pk_test_current')->create()->environments()->sole();

    presentApiKey('ingest', 'pk_test_unknown');

    expect($environment->fresh()->api_key_last_used_at)->toBeNull();
});

it('keeps API keys and ingest tokens out of Telescope entries', function () {
    expect(Telescope::$hiddenRequestHeaders)->toContain('authorization', 'x-api-key')
        ->and(Telescope::$hiddenResponseParameters)->toContain('token');
});
