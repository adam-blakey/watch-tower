<?php

use App\Watch\Databases;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        $environmentIds = DB::table('environments')->pluck('id', 'project_id');

        foreach ([...Databases::TELEMETRY_TABLES, ...Databases::LOG_TABLES] as $table) {
            $connection = DB::connection(Databases::connectionFor($table));

            if (! $connection->getSchemaBuilder()->hasColumn($table, 'environment_id')) {
                continue;
            }

            foreach ($environmentIds as $projectId => $environmentId) {
                $connection->table($table)
                    ->where('project_id', $projectId)
                    ->whereNull('environment_id')
                    ->update(['environment_id' => $environmentId]);
            }
        }
    }

    public function down(): void {}
};
