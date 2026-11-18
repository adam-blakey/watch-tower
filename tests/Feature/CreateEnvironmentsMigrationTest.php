<?php

use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
});

/**
 * The migration that moved the API keys from projects to environments.
 */
function createEnvironmentsMigration(): Migration
{
    return require glob(database_path('migrations/*_create_environments_table.php'))[0];
}

/**
 * Insert a project the way the schema before environments stored it: with its key columns on the row.
 *
 * @param  array<string, mixed>  $keyColumns
 */
function insertProjectWithKey(string $apiKey, array $keyColumns = []): string
{
    $id = (string) Str::uuid();

    DB::table('projects')->insert([
        'id' => $id,
        'organization_id' => Organization::factory()->create()->id,
        'name' => 'Before environments',
        'slug' => 'before-'.Str::lower(Str::random(5)),
        ...Environment::apiKeyAttributes($apiKey),
        ...$keyColumns,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('moves each project key to a default production environment that keeps authenticating', function () {
    $migration = createEnvironmentsMigration();
    $migration->down();

    $id = insertProjectWithKey('pk_before_current', [
        'previous_api_key_hash' => hash('sha256', 'pk_before_previous'),
        'previous_api_key_expires_at' => now()->addHour(),
        'api_key_version' => 2,
        'api_key_last_used_at' => now()->subDay(),
    ]);
    $tokenIssuedBefore = Crypt::encryptString(json_encode(['project_id' => $id, 'expires_at' => time() + 3600, 'key_version' => 2]));

    $migration->up();

    $environment = Project::findOrFail($id)->defaultEnvironment;

    expect(Schema::hasColumn('projects', 'api_key_hash'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'api_key_version'))->toBeFalse()
        ->and($environment->only(['name', 'slug', 'is_default', 'api_key_prefix', 'api_key_version']))->toBe([
            'name' => 'Production',
            'slug' => 'production',
            'is_default' => true,
            'api_key_prefix' => 'pk_before_',
            'api_key_version' => 2,
        ])
        ->and($environment->previous_api_key_expires_at->equalTo(now()->addHour()))->toBeTrue()
        ->and($environment->api_key_last_used_at->equalTo(now()->subDay()))->toBeTrue();

    foreach (['pk_before_current', 'pk_before_previous'] as $key) {
        $this->withHeader('Authorization', 'Bearer '.$key)->postJson('/api/agent-auth')->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$key)
            ->postJson('/api/ingest', ['events' => [['type' => 'request', 'data' => ['request_id' => 'req-1']]]])
            ->assertStatus(202);
    }

    postNightwatchRecords([], $tokenIssuedBefore)->assertOk();
});

it('gives every project its own default environment', function () {
    $migration = createEnvironmentsMigration();
    $migration->down();

    $first = insertProjectWithKey('pk_before_first');
    $second = insertProjectWithKey('pk_before_second');
    $migration->up();

    expect(Environment::findByApiKey('pk_before_first')?->project_id)->toBe($first)
        ->and(Environment::findByApiKey('pk_before_second')?->project_id)->toBe($second)
        ->and(Environment::count())->toBe(2);
});

it('puts the default environment key back on the project when rolled back', function () {
    $migration = createEnvironmentsMigration();
    $project = Project::factory()->withApiKey('pk_test_default')->create();
    Environment::factory()->for($project)->withApiKey('pk_test_staging')->create();
    $project->defaultEnvironment->rotateApiKey(gracePeriodSeconds: 3600);

    $migration->down();

    $row = DB::table('projects')->where('id', $project->id)->first();

    expect(Schema::hasTable('environments'))->toBeFalse()
        ->and($row->previous_api_key_hash)->toBe(hash('sha256', 'pk_test_default'))
        ->and((int) $row->api_key_version)->toBe(2);

    $migration->up();

    $this->withHeader('Authorization', 'Bearer pk_test_default')->postJson('/api/agent-auth')->assertOk();
    $this->withHeader('Authorization', 'Bearer pk_test_staging')->postJson('/api/agent-auth')->assertUnauthorized();
});
