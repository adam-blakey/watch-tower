<?php

namespace App\Jobs;

use App\Models\Environment;
use App\Watch\TelemetryPruner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Finishes deleting an environment that `EnvironmentController::destroy` has already hidden (`deleting_at` is set).
 *
 * Deletes the telemetry and log rows stamped with the environment's id, in chunks, then the environment row. Rows
 * without an `environment_id` belong to the default environment and are never touched. Time-boxed and re-dispatched
 * like {@see DeleteProjectData}; every step is idempotent, so a retry continues where the last attempt stopped.
 */
class DeleteEnvironmentData implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 80;

    public function __construct(public readonly string $environmentId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->environmentId))->releaseAfter(30)->expireAfter(120),
        ];
    }

    public function handle(TelemetryPruner $pruner): void
    {
        $environment = Environment::withoutGlobalScope('notDeleting')->find($this->environmentId);

        if (! $environment || $environment->deleting_at === null || $environment->is_default) {
            return;
        }

        $timeBoxed = $this->job !== null && ! $this->job instanceof SyncJob;

        $result = $timeBoxed
            ? $pruner->purge($environment->project_id, null, microtime(true) + DeleteProjectData::RUN_BUDGET_SECONDS, $environment->id)
            : $pruner->purge($environment->project_id, environmentId: $environment->id);

        if (! $result['finished']) {
            self::dispatch($this->environmentId)->delay(5);

            return;
        }

        $environment->delete();

        $pruner->purge($environment->project_id, environmentId: $environment->id);
    }
}
