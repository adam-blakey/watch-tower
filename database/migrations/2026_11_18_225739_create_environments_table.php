<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The columns that hold a project's API key, which move to its default environment.
     *
     * @var list<string>
     */
    private const KEY_COLUMNS = [
        'api_key_hash',
        'api_key_prefix',
        'previous_api_key_hash',
        'previous_api_key_expires_at',
        'api_key_version',
        'api_key_last_used_at',
    ];

    /**
     * Give every project a default "Production" environment that takes over its API key.
     *
     * The key columns are copied unchanged, hash, grace period and version included, so deployed agents keep
     * authenticating with the keys they have and the agent tokens already issued stay valid. The columns are then
     * dropped from `projects`. They stay nullable on `environments`, as they were on `projects`, so a project that
     * somehow has no key still migrates.
     */
    public function up(): void
    {
        Schema::create('environments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 64);
            $table->boolean('is_default')->default(false);
            $this->addKeyColumns($table);
            $table->timestamps();

            $table->unique(['project_id', 'slug']);
        });

        DB::table('projects')->select(['id', ...self::KEY_COLUMNS])->orderBy('id')->chunkById(100, function ($projects): void {
            DB::table('environments')->insert($projects->map(fn (object $project): array => [
                'id' => (string) Str::uuid7(),
                'project_id' => $project->id,
                'name' => 'Production',
                'slug' => 'production',
                'is_default' => true,
                ...Arr::only((array) $project, self::KEY_COLUMNS),
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());
        });

        // The indexes go first: SQLite refuses to drop an indexed column.
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique(['api_key_hash']);
            $table->dropIndex(['previous_api_key_hash']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(self::KEY_COLUMNS);
        });
    }

    /**
     * Put each project's key back on `projects`, taken from its default environment, and drop the environments.
     *
     * The keys of the other environments are lost: agents that use them stop authenticating.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $this->addKeyColumns($table);
        });

        DB::table('environments')->where('is_default', true)->select(['id', 'project_id', ...self::KEY_COLUMNS])->orderBy('id')->chunkById(100, function ($environments): void {
            foreach ($environments as $environment) {
                DB::table('projects')->where('id', $environment->project_id)->update(Arr::only((array) $environment, self::KEY_COLUMNS));
            }
        });

        Schema::drop('environments');
    }

    private function addKeyColumns(Blueprint $table): void
    {
        $table->char('api_key_hash', 64)->nullable()->unique();
        $table->string('api_key_prefix', 16)->nullable();
        $table->char('previous_api_key_hash', 64)->nullable()->index();
        $table->timestamp('previous_api_key_expires_at')->nullable();
        $table->unsignedInteger('api_key_version')->default(1);
        $table->timestamp('api_key_last_used_at')->nullable();
    }
};
