<?php

use App\Watch\Databases;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = Databases::TELEMETRY;

    /**
     * The telemetry tables that record the environment of their rows, with the time column their new index ends on.
     *
     * `error_groups` is left out: an issue spans every environment of its project, and its occurrences carry the
     * environment.
     *
     * @var array<string, string|null>
     */
    private const TABLES = [
        'traces' => 'occurred_at',
        'trace_queries' => 'occurred_at',
        'error_occurrences' => 'occurred_at',
        'event_occurrences' => 'occurred_at',
        'queue_job_runs' => null,
        'command_runs' => 'occurred_at',
        'scheduled_task_runs' => 'occurred_at',
        'mail_sends' => 'occurred_at',
        'notification_sends' => 'occurred_at',
        'cache_events' => 'occurred_at',
        'outgoing_requests' => 'occurred_at',
        'end_users' => null,
        'metrics' => 'period_start',
    ];

    /**
     * Add a nullable `environment_id` to every telemetry table that has a `project_id`.
     *
     * Existing rows are not backfilled: a null `environment_id` means the project's default environment. There is no
     * foreign key, because environments live in the app database. Tables that already have the column are skipped:
     * `migrate:fresh` resets only the app database and runs this again over telemetry that kept it.
     */
    public function up(): void
    {
        $schema = Schema::connection(Databases::TELEMETRY);

        if ($schema->getConnection()->pretending()) {
            return;
        }

        foreach (self::TABLES as $tableName => $timeColumn) {
            if ($schema->hasColumn($tableName, 'environment_id')) {
                continue;
            }

            $schema->table($tableName, function (Blueprint $table) use ($tableName, $timeColumn): void {
                $table->uuid('environment_id')->nullable();
                $table->index(array_filter(['project_id', 'environment_id', $timeColumn]), "{$tableName}_environment_index");
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $tableName) {
            Schema::connection(Databases::TELEMETRY)->table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropIndex("{$tableName}_environment_index");
            });

            Schema::connection(Databases::TELEMETRY)->table($tableName, function (Blueprint $table): void {
                $table->dropColumn('environment_id');
            });
        }
    }
};
