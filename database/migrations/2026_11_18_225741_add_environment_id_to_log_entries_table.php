<?php

use App\Watch\Databases;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = Databases::LOGS;

    /**
     * Add a nullable `environment_id` to the log entries.
     *
     * Existing rows are not backfilled: a null `environment_id` means the project's default environment. Adding a
     * column does not rebuild the table on SQLite, so the log search triggers stay in place. A table that already has
     * the column is left alone: `migrate:fresh` resets only the app database and runs this again over the logs.
     */
    public function up(): void
    {
        $schema = Schema::connection(Databases::LOGS);

        if ($schema->getConnection()->pretending() || $schema->hasColumn('log_entries', 'environment_id')) {
            return;
        }

        $schema->table('log_entries', function (Blueprint $table): void {
            $table->uuid('environment_id')->nullable();
            $table->index(['project_id', 'environment_id', 'occurred_at'], 'log_entries_environment_index');
        });
    }

    public function down(): void
    {
        Schema::connection(Databases::LOGS)->table('log_entries', function (Blueprint $table): void {
            $table->dropIndex('log_entries_environment_index');
        });

        Schema::connection(Databases::LOGS)->table('log_entries', function (Blueprint $table): void {
            $table->dropColumn('environment_id');
        });
    }
};
