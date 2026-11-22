<?php

use App\Watch\Databases;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = Databases::TELEMETRY;

    /**
     * Key end users on their environment too: user ids come from each environment's own database, so the same id in
     * staging and production is two different people.
     */
    public function up(): void
    {
        $schema = Schema::connection(Databases::TELEMETRY);

        // The telemetry database outlives `migrate:fresh` of the app database, so it can already be scoped.
        if ($schema->getConnection()->pretending() || ! $schema->hasIndex('end_users', ['project_id', 'identifier'], 'unique')) {
            return;
        }

        $schema->table('end_users', function (Blueprint $table): void {
            $table->dropUnique(['project_id', 'identifier']);
            $table->unique(['project_id', 'environment_id', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::connection(Databases::TELEMETRY)->table('end_users', function (Blueprint $table): void {
            $table->dropUnique(['project_id', 'environment_id', 'identifier']);
            $table->unique(['project_id', 'identifier']);
        });
    }
};
