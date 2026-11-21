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

    public function up(): void
    {
        $projectId = collect(Schema::getColumns('projects'))->firstWhere('name', 'id');

        Schema::create('environments', function (Blueprint $table) use ($projectId): void {
            $table->uuid('id')->primary();
            if (($projectId['type_name'] ?? 'uuid') === 'uuid') {
                $table->uuid('project_id');
            } else {
                $table->char('project_id', 36)->collation($projectId['collation']);
            }
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 64);
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
                ...Arr::only((array) $project, self::KEY_COLUMNS),
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique(['api_key_hash']);
            $table->dropIndex(['previous_api_key_hash']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(self::KEY_COLUMNS);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $this->addKeyColumns($table);
        });

        DB::table('environments')->orderBy('id')->get(['project_id', ...self::KEY_COLUMNS])->unique('project_id')->each(
            fn (object $environment) => DB::table('projects')->where('id', $environment->project_id)->update(Arr::only((array) $environment, self::KEY_COLUMNS)),
        );

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
