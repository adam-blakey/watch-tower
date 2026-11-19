<?php

namespace App\Watch;

use App\Models\CacheEvent;
use App\Models\CommandRun;
use App\Models\EndUser;
use App\Models\ErrorGroup;
use App\Models\ErrorOccurrence;
use App\Models\EventOccurrence;
use App\Models\LogEntry;
use App\Models\MailSend;
use App\Models\NotificationSend;
use App\Models\OutgoingRequest;
use App\Models\Project;
use App\Models\QueueJobRun;
use App\Models\ScheduledTaskRun;
use App\Models\Trace;
use App\Models\TraceQuery;
use App\Models\User;
use App\Notifications\IssueRaised;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class EventStore
{
    /**
     * Every event type the store accepts; shared with the legacy ingest request validation.
     *
     * @var list<string>
     */
    public const TYPES = ['request', 'query', 'exception', 'event', 'job', 'job-attempt', 'log', 'cache-event', 'command', 'scheduled-task', 'mail', 'notification', 'outgoing-request', 'user'];

    public function __construct(private readonly Fingerprinter $fingerprinter) {}

    /**
     * Persist a single ingested event for a project.
     *
     * @param  array{type: string, id?: string, data: array<string, mixed>}  $event
     */
    public function store(Project $project, array $event): void
    {
        match ($event['type']) {
            'request' => $this->storeRequest($project, $event['data']),
            'query' => $this->storeQuery($project, $event['data']),
            'exception' => $this->storeException($project, $event['data']),
            'event' => $this->storeEvent($project, $event['data']),
            'job' => $this->storeJob($project, $event['data']),
            'job-attempt' => $this->storeJobAttempt($project, $event['data']),
            'log' => $this->storeLog($project, $event['data']),
            'cache-event' => $this->storeCacheEvent($project, $event['data']),
            'command' => $this->storeCommand($project, $event['data']),
            'scheduled-task' => $this->storeScheduledTask($project, $event['data']),
            'mail' => $this->storeMail($project, $event['data']),
            'notification' => $this->storeNotification($project, $event['data']),
            'outgoing-request' => $this->storeOutgoingRequest($project, $event['data']),
            'user' => $this->storeUser($project, $event['data']),
            default => null,
        };
    }

    /**
     * Persist the request record, filling in the stub trace if child records arrived first.
     *
     * The trace is keyed on (project, correlation id), so a request can never touch another project's trace.
     * `has_errors` and `has_slow_queries` are only ever raised: a request record that does not report them must not
     * undo what the child records already flagged. The same goes for `user_name` and `user_email`, which a `user`
     * record may have filled in already. `db_time_ms` is the sum of the stored query durations, kept up to date by
     * `storeQuery()`, so it is only written when the record carries it (legacy clients): a request record from
     * Nightwatch has no such field and must not reset what queries that arrived first already added. A legacy client
     * that sends `db_time_ms` on the request and also sends query records ends up anywhere between the request's
     * value X and X plus the query sum: the request record overwrites what earlier queries added, and later queries
     * add on top. When the request
     * carries a user but no details were known while storing it, the end user is read again afterwards, see
     * `completeUserDetails()`. The route details, sizes and stage timings are only written when present, so a
     * request record without them (a legacy client) does not erase what an earlier record stored.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeRequest(Project $project, array $data): void
    {
        $correlationId = $this->limitedString($data['request_id'] ?? $data['correlation_id'] ?? null, 255);

        if ($correlationId === null) {
            return;
        }

        $userIdentifier = $this->limitedString($data['user_id'] ?? null, 255);
        $userDetails = $this->userDetails($project, $userIdentifier, $data);

        $values = [
            'method' => $this->limitedString($data['method'] ?? null, 10) ?? 'GET',
            'uri' => is_scalar($data['uri'] ?? null) ? (string) $data['uri'] : '/',
            'status_code' => $this->unsignedIntOrNull($data['status_code'] ?? null, 65_535),
            'user_identifier' => $userIdentifier,
            ...$userDetails,
            'duration_ms' => $this->unsignedIntOrNull($data['duration_ms'] ?? null),
            'db_queries_count' => $this->unsignedIntOrNull($data['db_queries_count'] ?? null) ?? 0,
            ...array_filter([
                'lazy_loads' => $this->unsignedIntOrNull($data['lazy_loads'] ?? null),
                'hydrated_models' => $this->unsignedIntOrNull($data['hydrated_models'] ?? null),
            ], fn (mixed $value): bool => $value !== null),
            'memory_used_kb' => $this->unsignedIntOrNull($data['memory_used_kb'] ?? null),
            'memory_peak_kb' => $this->unsignedIntOrNull($data['memory_peak_kb'] ?? null),
            'environment' => $this->limitedString($data['environment'] ?? null, 50),
            'release_version' => $this->limitedString($data['release'] ?? null, 255),
            'hostname' => $this->limitedString($data['hostname'] ?? null, 255),
            'ip_address' => $this->limitedString($data['ip_address'] ?? null, 45),
            ...array_filter([
                'route_name' => $this->limitedString($data['route_name'] ?? null, 255),
                'route_methods' => $this->routeMethodsOrNull($data['route_methods'] ?? null),
                'route_domain' => $this->limitedString($data['route_domain'] ?? null, 255),
                'route_action' => $this->limitedString($data['route_action'] ?? null, 255),
                'request_size_bytes' => $this->unsignedIntOrNull($data['request_size_bytes'] ?? null),
                'response_size_bytes' => $this->unsignedIntOrNull($data['response_size_bytes'] ?? null),
                'bootstrap_ms' => $this->fractionalMsOrNull($data['bootstrap_ms'] ?? null),
                'before_middleware_ms' => $this->fractionalMsOrNull($data['before_middleware_ms'] ?? null),
                'action_ms' => $this->fractionalMsOrNull($data['action_ms'] ?? null),
                'render_ms' => $this->fractionalMsOrNull($data['render_ms'] ?? null),
                'after_middleware_ms' => $this->fractionalMsOrNull($data['after_middleware_ms'] ?? null),
                'sending_ms' => $this->fractionalMsOrNull($data['sending_ms'] ?? null),
                'terminating_ms' => $this->fractionalMsOrNull($data['terminating_ms'] ?? null),
            ], fn (mixed $value): bool => $value !== null),
            'user_agent' => $this->stringOrNull($data['user_agent'] ?? null),
            'headers' => $this->boundedBlob($this->redactSensitiveHeaders($data['headers'] ?? null)),
            'request_data' => $this->boundedBlob($data['request_data'] ?? null),
            'response_data' => $this->boundedBlob($data['response_data'] ?? null),
            'is_stub' => false,
            'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? $data['timestamp'] ?? null),
            ...array_filter([
                'has_errors' => (bool) ($data['has_errors'] ?? false),
                'has_slow_queries' => (bool) ($data['has_slow_queries'] ?? false),
            ]),
            ...(array_key_exists('db_time_ms', $data) ? ['db_time_ms' => $this->fractionalMsOrNull($data['db_time_ms']) ?? 0.0] : []),
        ];

        $trace = Trace::firstOrCreate(
            ['project_id' => $project->id, 'correlation_id' => $correlationId],
            $values,
        );

        if (! $trace->wasRecentlyCreated) {
            $trace->fill($values)->save();
        }

        if ($userIdentifier !== null && $userDetails === []) {
            $this->completeUserDetails(Trace::class, $project, $trace->id, $userIdentifier);
        }
    }

    /**
     * Persist a query against its trace, which is created as a stub if the request record has not arrived yet.
     *
     * A query without any correlation id has no trace to belong to (`trace_id` is required), so it is the only case
     * that is dropped, and it is logged rather than discarded silently. The query's duration is added to the
     * trace's `db_time_ms` in the same transaction, so the sum always equals the stored query durations. The trace row
     * is updated before the query row is inserted: the insert takes a shared lock on the parent trace row (foreign
     * key), and upgrading it to the exclusive lock of an UPDATE afterwards can deadlock on MySQL when two queries of
     * one request are stored concurrently.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeQuery(Project $project, array $data): void
    {
        $trace = $this->resolveTrace($project, $data);

        if (! $trace) {
            Log::warning('Dropped a query record that carries no correlation id.', ['project_id' => $project->id]);

            return;
        }

        $durationMs = $this->fractionalMsOrNull($data['duration_ms'] ?? null) ?? 0.0;
        $file = $this->limitedString($data['file'] ?? null, 255);

        DB::connection(Databases::TELEMETRY)->transaction(function () use ($trace, $durationMs, $file, $project, $data): void {
            if ($durationMs > 0) {
                Trace::query()->whereKey($trace->id)->increment('db_time_ms', $durationMs);
            }

            TraceQuery::create([
                'project_id' => $project->id,
                'trace_id' => $trace->id,
                'query_type' => $this->detectQueryType($this->stringOrNull($data['sql'] ?? null) ?? ''),
                'sql' => $this->stringOrNull($data['sql'] ?? null) ?? '',
                'bindings' => $this->boundedBlob($data['bindings'] ?? null),
                'connection_name' => $this->limitedString($data['connection'] ?? null, 100),
                'file' => $file,
                'line' => $file === null ? null : ($this->unsignedIntOrNull($data['line'] ?? null) ?: null),
                'connection_type' => $this->limitedString($data['connection_type'] ?? null, 10),
                'duration_ms' => $durationMs,
                'row_count' => $this->unsignedIntOrNull($data['row_count'] ?? null, 9_007_199_254_740_991),
                'is_n_plus_one' => (bool) ($data['is_n_plus_one'] ?? false),
                'n_plus_one_group' => $this->limitedString($data['n_plus_one_group'] ?? null, 255),
                'is_slow' => (bool) ($data['is_slow'] ?? false),
                'source_type' => $this->limitedString($data['source_type'] ?? null, 50),
                'source_label' => $this->limitedString($data['source_label'] ?? null, 255),
                'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? null),
            ]);
        }, attempts: 3);
    }

    /**
     * The trace is marked `has_errors` first, before the occurrence insert: the insert takes a shared foreign key
     * lock on the trace row, and upgrading that to the exclusive lock of the update afterwards can deadlock with
     * another job that updates the same trace row.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeException(Project $project, array $data): void
    {
        $trace = $this->resolveTrace($project, $data);

        $fingerprint = $this->fingerprinter->forException($data);
        $exceptionClass = $this->limitedString($data['class'] ?? $data['exception_class'] ?? null, 255) ?? 'Exception';
        $message = $this->stringOrNull($data['message'] ?? null);
        $file = $this->limitedString($data['file'] ?? null, 255);
        $line = $this->unsignedIntOrNull($data['line'] ?? null);
        $occurredAt = $this->parseTimestamp($data['occurred_at'] ?? null);
        $userIdentifier = $this->limitedString($data['user_id'] ?? null, 255);
        $userDetails = $this->userDetails($project, $userIdentifier, $data);
        $isHandled = filter_var($data['is_handled'] ?? $data['handled'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
        $languageVersion = $this->limitedString($data['language_version'] ?? null, 50);
        $frameworkVersion = $this->limitedString($data['framework_version'] ?? null, 50);
        $hostname = $this->limitedString($data['hostname'] ?? null, 255);
        $release = $this->limitedString($data['release'] ?? null, 255);
        $sourceType = $this->limitedString($data['source_type'] ?? null, 50);
        $sourceLabel = $this->limitedString($data['source_label'] ?? null, 255);
        $raised = null;

        $occurrence = DB::connection(Databases::TELEMETRY)->transaction(function () use ($project, $trace, $fingerprint, $exceptionClass, $message, $file, $line, $occurredAt, $data, $userIdentifier, $userDetails, $isHandled, $languageVersion, $frameworkVersion, $hostname, $release, $sourceType, $sourceLabel, &$raised) {
            if ($trace && ! $trace->has_errors) {
                $trace->forceFill(['has_errors' => true])->save();
            }

            $group = ErrorGroup::where('project_id', $project->id)
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($group) {
                $raised = $this->recordRecurrence($group, $occurredAt, [
                    'is_handled' => $isHandled,
                    'language_version' => $languageVersion,
                    'framework_version' => $frameworkVersion,
                ]);
            } else {
                $nextDisplayNumber = (int) (ErrorGroup::where('project_id', $project->id)->max('display_number') ?? 0) + 1;

                $group = ErrorGroup::create([
                    'project_id' => $project->id,
                    'fingerprint' => $fingerprint,
                    'display_number' => $nextDisplayNumber,
                    'exception_class' => $exceptionClass,
                    'first_message' => $message,
                    'first_file' => $file,
                    'first_line' => $line,
                    'is_handled' => $isHandled,
                    'language_version' => $languageVersion,
                    'framework_version' => $frameworkVersion,
                    'total_count' => 1,
                    'first_occurrence_at' => $occurredAt,
                    'last_occurrence_at' => $occurredAt,
                    'status' => 'unresolved',
                ]);

                $raised = 'new';
            }

            $occurrence = ErrorOccurrence::create([
                'project_id' => $project->id,
                'trace_id' => $trace?->id,
                'error_group_id' => $group->id,
                'exception_class' => $exceptionClass,
                'message' => $message,
                'stacktrace' => $this->boundedBlob($data['trace'] ?? $data['stacktrace'] ?? [], overflow: []),
                'fingerprint' => $fingerprint,
                'user_identifier' => $userIdentifier,
                'user_name' => $userDetails['user_name'] ?? null,
                'user_email' => $userDetails['user_email'] ?? null,
                'file' => $file,
                'line' => $line,
                'is_handled' => $isHandled,
                'environment' => $this->limitedString($data['environment'] ?? null, 50),
                'release_version' => $release,
                'language_version' => $languageVersion,
                'framework_version' => $frameworkVersion,
                'hostname' => $hostname,
                'context' => $this->boundedBlob($data['context'] ?? null),
                'source_type' => $sourceType,
                'source_label' => $sourceLabel,
                'occurred_at' => $occurredAt,
            ]);

            return $occurrence;
        });

        if ($raised !== null) {
            $this->notifyIssueRaised($project, $occurrence, $raised === 'regression');
        }

        if ($userIdentifier !== null && $userDetails === []) {
            $this->completeUserDetails(ErrorOccurrence::class, $project, $occurrence->id, $userIdentifier);
        }
    }

    /**
     * Email everyone who can see the project (super admins and the project's admins, as the `view-project` gate) once
     * per new or reopened issue. It runs after the transaction commits, and the row lock on the group means only one
     * concurrent job sees the transition. A mail or queue failure is reported but never fails the ingest job, whose
     * retry would store the occurrence twice.
     */
    private function notifyIssueRaised(Project $project, ErrorOccurrence $occurrence, bool $isRegression): void
    {
        rescue(function () use ($project, $occurrence, $isRegression): void {
            $recipients = User::query()
                ->where('role', User::ROLE_SUPER_ADMIN)
                ->orWhereHas('projects', fn ($query) => $query->whereKey($project->getKey()))
                ->get();

            Notification::send($recipients, new IssueRaised($occurrence->errorGroup, $occurrence, $isRegression));
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeEvent(Project $project, array $data): void
    {
        $trace = $this->resolveTrace($project, $data);

        EventOccurrence::create([
            'project_id' => $project->id,
            'trace_id' => $trace?->id,
            'event_class' => $this->limitedString($data['class'] ?? $data['event_class'] ?? null, 255) ?? 'UnknownEvent',
            'fired_by' => $this->limitedString($data['fired_by'] ?? null, 255),
            'duration_ms' => $this->unsignedIntOrNull($data['duration_ms'] ?? null),
            'listeners_count' => $this->unsignedIntOrNull($data['listeners_count'] ?? null) ?? 0,
            'payload' => $this->boundedBlob($data['payload'] ?? null),
            'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? null),
        ]);
    }

    /**
     * Persist a dispatched (queued) job.
     *
     * With a `job_id` the run is keyed on (project, job id) and shared with its attempts: if an attempt record got
     * here first, the queued record only fills the dispatch details the row lacks and never touches the outcome.
     * Without a `job_id` (legacy `job` events) every record is its own row.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeJob(Project $project, array $data): void
    {
        $trace = $this->resolveTrace($project, $data);

        $jobId = $this->limitedString($data['job_id'] ?? null, 64);

        $values = [
            'project_id' => $project->id,
            'trace_id' => $trace?->id,
            'job_id' => $jobId,
            'job_class' => $this->limitedString($data['class'] ?? $data['job_class'] ?? null, 255) ?? 'UnknownJob',
            'queue' => $this->limitedString($data['queue'] ?? null, 100),
            'connection' => $this->limitedString($data['connection'] ?? null, 100),
            'dispatched_at' => $this->parseTimestamp($data['dispatched_at'] ?? null, allowNull: true),
            'started_at' => $this->parseTimestamp($data['started_at'] ?? null, allowNull: true),
            'completed_at' => $this->parseTimestamp($data['completed_at'] ?? null, allowNull: true),
            'failed_at' => $this->parseTimestamp($data['failed_at'] ?? null, allowNull: true),
            'duration_ms' => $this->unsignedIntOrNull($data['duration_ms'] ?? null),
            'attempts' => $this->unsignedIntOrNull($data['attempts'] ?? null) ?? 0,
            'status' => $this->limitedString($data['status'] ?? null, 50) ?? 'pending',
            'payload' => $this->boundedBlob($data['payload'] ?? null),
            'user_identifier' => $this->limitedString($data['user_id'] ?? null, 255),
            'exception' => $this->boundedBlob($data['exception'] ?? null),
            'environment' => $this->limitedString($data['environment'] ?? null, 50),
        ];

        if ($jobId === null) {
            QueueJobRun::create($values);

            return;
        }

        $run = QueueJobRun::firstOrCreate(['project_id' => $project->id, 'job_id' => $jobId], $values);

        if ($run->wasRecentlyCreated) {
            return;
        }

        $this->fillJobRunBlanks($run, $values);
        $run->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeCacheEvent(Project $project, array $data): void
    {
        $trace = $this->resolveTrace($project, $data);

        CacheEvent::create([
            'project_id' => $project->id,
            'trace_id' => $trace?->id,
            'key' => $this->limitedString($data['key'] ?? null, 255) ?? '',
            'store' => $this->limitedString($data['store'] ?? null, 50),
            'operation' => $this->limitedString($data['operation'] ?? null, 20) ?? 'unknown',
            'succeeded' => (bool) ($data['succeeded'] ?? true),
            'duration_ms' => $this->unsignedIntOrNull($data['duration_ms'] ?? null),
            'source_type' => $this->limitedString($data['source_type'] ?? null, 50),
            'source_label' => $this->limitedString($data['source_label'] ?? null, 255),
            'environment' => $this->limitedString($data['environment'] ?? null, 50),
            'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? null),
        ]);
    }

    /**
     * Persist one attempt of a job onto the job's run, which is keyed on (project, job id).
     *
     * The run holds the outcome of the newest attempt (by start time), so attempts arriving out of order or twice
     * leave it unchanged. Without a `job_id` the attempt is stored as a standalone row. Per-attempt metrics (peak memory
     * and the lazy load and hydrated model counts) follow the same rule, and an attempt that does not carry them leaves
     * the stored values alone: a newer attempt without them keeps the previous attempt's values.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeJobAttempt(Project $project, array $data): void
    {
        $trace = $this->resolveTrace($project, $data);
        $jobId = $this->limitedString($data['job_id'] ?? null, 64);
        $startedAt = $this->parseTimestamp($data['started_at'] ?? null, allowNull: true);

        $outcome = [
            'status' => $this->limitedString($data['status'] ?? null, 50) ?? 'unknown',
            'attempts' => $this->unsignedIntOrNull($data['attempts'] ?? null) ?? 1,
            ...array_filter(
                ['memory_peak_kb' => $this->unsignedIntOrNull($data['memory_peak_kb'] ?? null)],
                fn (mixed $value): bool => $value !== null,
            ),
            'started_at' => $startedAt,
            'completed_at' => $this->parseTimestamp($data['completed_at'] ?? null, allowNull: true),
            'failed_at' => $this->parseTimestamp($data['failed_at'] ?? null, allowNull: true),
            'duration_ms' => $this->unsignedIntOrNull($data['duration_ms'] ?? null),
            ...array_filter([
                'lazy_loads' => $this->unsignedIntOrNull($data['lazy_loads'] ?? null),
                'hydrated_models' => $this->unsignedIntOrNull($data['hydrated_models'] ?? null),
            ], fn (mixed $value): bool => $value !== null),
            'exception' => $this->boundedBlob($data['exception'] ?? null),
        ];

        $details = [
            'trace_id' => $trace?->id,
            'job_class' => $this->limitedString($data['class'] ?? $data['job_class'] ?? null, 255) ?? 'UnknownJob',
            'queue' => $this->limitedString($data['queue'] ?? null, 100),
            'connection' => $this->limitedString($data['connection'] ?? null, 100),
            'user_identifier' => $this->limitedString($data['user_id'] ?? null, 255),
            'environment' => $this->limitedString($data['environment'] ?? null, 50),
        ];

        $values = ['project_id' => $project->id, 'job_id' => $jobId, 'dispatched_at' => null, ...$details, ...$outcome];

        if ($jobId === null) {
            QueueJobRun::create($values);

            return;
        }

        $run = QueueJobRun::firstOrCreate(['project_id' => $project->id, 'job_id' => $jobId], $values);

        if ($run->wasRecentlyCreated) {
            return;
        }

        DB::connection(Databases::TELEMETRY)->transaction(function () use ($run, $startedAt, $outcome, $details): void {
            $locked = QueueJobRun::whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($this->isNewestAttempt($locked, $startedAt, $outcome['attempts'])) {
                $locked->fill($outcome);
            }

            $this->fillJobRunBlanks($locked, $details);
            $locked->save();
        }, attempts: 3);
    }

    /**
     * Whether an attempt is at least as recent as the one the run currently reflects.
     *
     * Start times are stored with second precision, so attempts that began within the same second are ordered by
     * attempt number instead.
     */
    private function isNewestAttempt(QueueJobRun $run, ?CarbonImmutable $startedAt, int $attempts): bool
    {
        if ($run->started_at === null) {
            return true;
        }

        if ($startedAt === null) {
            return false;
        }

        if ($startedAt->equalTo($run->started_at)) {
            return $attempts >= $run->attempts;
        }

        return $startedAt->greaterThan($run->started_at);
    }

    /**
     * Fill the run's empty columns from the candidates, and replace a placeholder job class; filled columns are kept.
     *
     * @param  array<string, mixed>  $candidates
     */
    private function fillJobRunBlanks(QueueJobRun $run, array $candidates): void
    {
        foreach (['dispatched_at', 'trace_id', 'queue', 'connection', 'user_identifier', 'environment'] as $column) {
            if ($run->{$column} === null && ($candidates[$column] ?? null) !== null) {
                $run->{$column} = $candidates[$column];
            }
        }

        $jobClass = $candidates['job_class'] ?? null;

        if ($run->job_class === 'UnknownJob' && $jobClass !== null && $jobClass !== 'UnknownJob') {
            $run->job_class = $jobClass;
        }
    }

    /**
     * Persist a command run. Command runs have no trace to link to, so the record is a plain insert.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeCommand(Project $project, array $data): void
    {
        CommandRun::create([
            'project_id' => $project->id,
            'command' => $this->limitedString($data['command'] ?? null, 255) ?? 'unknown',
            'memory_peak_kb' => $this->unsignedIntOrNull($data['memory_peak_kb'] ?? null),
            'arguments' => is_array($data['arguments'] ?? null) ? $this->boundedBlob($data['arguments']) : null,
            'options' => is_array($data['options'] ?? null) ? $this->boundedBlob($data['options']) : null,
            'lazy_loads' => $this->unsignedIntOrNull($data['lazy_loads'] ?? null),
            'hydrated_models' => $this->unsignedIntOrNull($data['hydrated_models'] ?? null),
            'status' => $this->limitedString($data['status'] ?? null, 50) ?? 'completed',
            'exit_code' => $this->intOrNull($data['exit_code'] ?? null),
            'duration_ms' => $this->unsignedIntOrNull($data['duration_ms'] ?? null),
            'bootstrap_ms' => $this->fractionalMsOrNull($data['bootstrap_ms'] ?? null),
            'action_ms' => $this->fractionalMsOrNull($data['action_ms'] ?? null),
            'terminating_ms' => $this->fractionalMsOrNull($data['terminating_ms'] ?? null),
            'output' => $this->stringOrNull($data['output'] ?? null),
            'environment' => $this->limitedString($data['environment'] ?? null, 50),
            'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? null),
        ]);
    }

    /**
     * Persist a scheduled task run as a plain insert. Runs are grouped by `task_hash`, which falls back to the sha1
     * of the task name unless the event carries a 40 character hex hash (the only shape the detail route accepts).
     *
     * @param  array<string, mixed>  $data
     */
    private function storeScheduledTask(Project $project, array $data): void
    {
        $task = $this->limitedString($data['task'] ?? null, 255) ?? 'unknown';
        $suppliedHash = $this->stringOrNull($data['task_hash'] ?? null);

        ScheduledTaskRun::create([
            'project_id' => $project->id,
            'task' => $task,
            'task_hash' => $suppliedHash !== null && preg_match('/^[0-9a-f]{40}$/i', $suppliedHash) ? $suppliedHash : sha1($task),
            'lazy_loads' => $this->unsignedIntOrNull($data['lazy_loads'] ?? null),
            'hydrated_models' => $this->unsignedIntOrNull($data['hydrated_models'] ?? null),
            'schedule' => $this->limitedString($data['schedule'] ?? null, 255),
            'schedule_summary' => $this->limitedString($data['schedule_summary'] ?? null, 255),
            'next_run_at' => $this->parseTimestamp($data['next_run_at'] ?? null, allowNull: true, allowFuture: true),
            'status' => $this->limitedString($data['status'] ?? null, 50) ?? 'processed',
            'exit_code' => $this->intOrNull($data['exit_code'] ?? null),
            'duration_ms' => $this->unsignedIntOrNull($data['duration_ms'] ?? null),
            'memory_peak_kb' => $this->unsignedIntOrNull($data['memory_peak_kb'] ?? null),
            'timezone' => $this->limitedString($data['timezone'] ?? null, 64),
            'repeat_seconds' => $this->unsignedIntOrNull($data['repeat_seconds'] ?? null, 65_535),
            'without_overlapping' => $this->boolOrNull($data['without_overlapping'] ?? null),
            'on_one_server' => $this->boolOrNull($data['on_one_server'] ?? null),
            'run_in_background' => $this->boolOrNull($data['run_in_background'] ?? null),
            'even_in_maintenance_mode' => $this->boolOrNull($data['even_in_maintenance_mode'] ?? null),
            'threshold_ms' => $this->unsignedIntOrNull($data['threshold_ms'] ?? null),
            'output' => $this->stringOrNull($data['output'] ?? null),
            'environment' => $this->limitedString($data['environment'] ?? null, 50),
            'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? null),
        ]);
    }

    /**
     * Persist a sent mail against its trace, which is created as a stub if the request record has not arrived yet.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeMail(Project $project, array $data): void
    {
        $trace = $this->resolveTrace($project, $data);

        MailSend::create([
            'project_id' => $project->id,
            'trace_id' => $trace?->id,
            'mailable_class' => $this->limitedString($data['class'] ?? $data['mailable_class'] ?? null, 255) ?? 'UnknownMailable',
            'mailer' => $this->limitedString($data['mailer'] ?? null, 50),
            'subject' => $this->limitedString($data['subject'] ?? null, 255),
            'recipients_count' => $this->unsignedIntOrNull($data['recipients_count'] ?? null) ?? 0,
            'attachments_count' => $this->unsignedIntOrNull($data['attachments_count'] ?? null) ?? 0,
            'status' => $this->limitedString($data['status'] ?? null, 50) ?? 'sent',
            'duration_ms' => $this->unsignedIntOrNull($data['duration_ms'] ?? null),
            ...$this->sourceAttributes($data),
            'environment' => $this->limitedString($data['environment'] ?? null, 50),
            'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeNotification(Project $project, array $data): void
    {
        $trace = $this->resolveTrace($project, $data);

        NotificationSend::create([
            'project_id' => $project->id,
            'trace_id' => $trace?->id,
            'notification_class' => $this->limitedString($data['class'] ?? $data['notification_class'] ?? null, 255) ?? 'UnknownNotification',
            'channel' => $this->limitedString($data['channel'] ?? null, 50) ?? 'unknown',
            'status' => $this->limitedString($data['status'] ?? null, 50) ?? 'sent',
            'duration_ms' => $this->unsignedIntOrNull($data['duration_ms'] ?? null),
            ...$this->sourceAttributes($data),
            'environment' => $this->limitedString($data['environment'] ?? null, 50),
            'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeOutgoingRequest(Project $project, array $data): void
    {
        $trace = $this->resolveTrace($project, $data);

        OutgoingRequest::create([
            'project_id' => $project->id,
            'trace_id' => $trace?->id,
            'method' => strtoupper($this->limitedString($data['method'] ?? null, 10) ?? 'GET'),
            'host' => $this->limitedString($data['host'] ?? null, 255) ?? '',
            'url' => $this->limitedString($data['url'] ?? null, 2048) ?? '',
            'status_code' => $this->unsignedIntOrNull($data['status_code'] ?? null, 65_535),
            'duration_ms' => $this->unsignedIntOrNull($data['duration_ms'] ?? null),
            'request_size_bytes' => $this->unsignedIntOrNull($data['request_size_bytes'] ?? null),
            'response_size_bytes' => $this->unsignedIntOrNull($data['response_size_bytes'] ?? null),
            ...$this->sourceAttributes($data),
            'environment' => $this->limitedString($data['environment'] ?? null, 50),
            'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? null),
        ]);
    }

    /**
     * The `source_*` columns shared by mail, notification and outgoing request rows.
     *
     * @param  array<string, mixed>  $data
     * @return array{source_type: string|null, source_id: string|null, source_label: string|null}
     */
    private function sourceAttributes(array $data): array
    {
        return [
            'source_type' => $this->limitedString($data['source_type'] ?? null, 50),
            'source_id' => $this->limitedString($data['source_id'] ?? null, 255),
            'source_label' => $this->limitedString($data['source_label'] ?? null, 255),
        ];
    }

    /**
     * Persist the name and email of an end user, and hand them to the traces and errors that were stored without.
     *
     * A `user` record is sent just before the `request` record it belongs to, but the two are processed by
     * independent queue jobs, so either can win. The two jobs cover each other: whichever comes second fills the
     * row. A request or exception stored first is filled here, and only when the end user is created, because
     * upstream sends a `user` record for every authenticated request and the backfill scans every retained row of
     * the user. One stored after the end user existed gets its details from `userDetails()`, or from
     * `completeUserDetails()` if the end user appeared in between.
     *
     * An existing end user is only updated by a record that is not older than the stored one, in a single
     * conditional UPDATE so that concurrent workers cannot move it backwards, and a missing value never erases a
     * stored one.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeUser(Project $project, array $data): void
    {
        $identifier = $this->limitedString($data['user_id'] ?? null, 255);
        $name = $this->limitedString($data['name'] ?? null, 255);
        $email = $this->limitedString($data['email'] ?? null, 255);

        if ($identifier === null || ($name === null && $email === null)) {
            return;
        }

        $seenAt = $this->parseTimestamp($data['occurred_at'] ?? null);

        $user = EndUser::firstOrCreate(
            ['project_id' => $project->id, 'identifier' => $identifier],
            ['name' => $name, 'email' => $email, 'last_seen_at' => $seenAt],
        );

        if (! $user->wasRecentlyCreated) {
            EndUser::whereKey($user->id)
                ->where('last_seen_at', '<=', $seenAt)
                ->update([
                    ...array_filter(['name' => $name, 'email' => $email], fn (?string $value): bool => $value !== null),
                    'last_seen_at' => $seenAt,
                ]);

            return;
        }

        $details = array_filter(
            ['user_name' => $name, 'user_email' => $email],
            fn (?string $value): bool => $value !== null,
        );

        foreach ([Trace::class, ErrorOccurrence::class] as $model) {
            $model::where('project_id', $project->id)
                ->where('user_identifier', $identifier)
                ->whereNull('user_name')
                ->whereNull('user_email')
                ->update($details);
        }
    }

    /**
     * The `user_name` and `user_email` to store with a record, leaving out whichever is unknown.
     *
     * Details sent with the record win; otherwise the ones a `user` record stored for the user are used. Unknown
     * values are left out rather than set to null so that spreading the result into an update never erases details
     * a `user` record backfilled.
     *
     * @param  array<string, mixed>  $data
     * @return array{user_name?: string, user_email?: string}
     */
    private function userDetails(Project $project, ?string $userIdentifier, array $data): array
    {
        $name = $this->limitedString($data['user_name'] ?? null, 255);
        $email = $this->limitedString($data['user_email'] ?? null, 255);

        if ($userIdentifier !== null && $name === null && $email === null) {
            $user = EndUser::where('project_id', $project->id)->where('identifier', $userIdentifier)->first();

            $name = $user?->name;
            $email = $user?->email;
        }

        return array_filter(
            ['user_name' => $name, 'user_email' => $email],
            fn (?string $value): bool => $value !== null,
        );
    }

    /**
     * Fill the user details of one freshly stored trace or error occurrence if its end user turned up meanwhile.
     *
     * The record was stored without details because no end user existed when `userDetails()` looked. A `user`
     * record processed in the meantime may have missed the row when it backfilled, so look again now that the row
     * is stored. Only this row is touched, by primary key, and only if it still has no details.
     *
     * @param  class-string<Trace|ErrorOccurrence>  $model
     */
    private function completeUserDetails(string $model, Project $project, string $rowId, string $userIdentifier): void
    {
        $user = EndUser::where('project_id', $project->id)->where('identifier', $userIdentifier)->first();

        if ($user === null) {
            return;
        }

        $details = array_filter(
            ['user_name' => $user->name, 'user_email' => $user->email],
            fn (?string $value): bool => $value !== null,
        );

        if ($details === []) {
            return;
        }

        $model::whereKey($rowId)->whereNull('user_name')->whereNull('user_email')->update($details);
    }

    /**
     * Count another occurrence against an existing error group.
     *
     * The counter is bumped with a single atomic UPDATE and `last_occurrence_at`
     * never moves backwards, so out-of-order events cannot rewind it. A resolved
     * group is reopened and the regression is logged on its timeline only when
     * the occurrence happened after it was resolved; an older event that was
     * still queued when the fix shipped is counted but leaves the group
     * resolved. An ignored group keeps its status.
     *
     * The group's handled flag and runtime versions follow its newest occurrence ("latest wins"): they are applied
     * only together with `last_occurrence_at`, and a version the occurrence did not report keeps the stored one.
     * Timestamps are stored at second precision, so of two occurrences in the same second the first one processed
     * keeps the flag and versions.
     *
     * @param  array{is_handled: bool, language_version: string|null, framework_version: string|null}  $latestDetails
     * @return 'regression'|null 'regression' when the occurrence reopened the group
     */
    private function recordRecurrence(ErrorGroup $group, CarbonImmutable $occurredAt, array $latestDetails): ?string
    {
        $attributes = [];

        if ($group->last_occurrence_at === null || $occurredAt->greaterThan($group->last_occurrence_at)) {
            $attributes['last_occurrence_at'] = $occurredAt;
            $attributes['is_handled'] = $latestDetails['is_handled'];

            foreach (['language_version', 'framework_version'] as $column) {
                if ($latestDetails[$column] !== null) {
                    $attributes[$column] = $latestDetails[$column];
                }
            }
        }

        $regression = null;

        $isRegression = $group->status === 'resolved'
            && ($group->resolved_at === null || $occurredAt->greaterThan($group->resolved_at));

        if ($isRegression) {
            $regression = [
                'previous_status' => 'resolved',
                'resolved_at' => $group->resolved_at?->toIso8601String(),
                'resolved_by_user_id' => $group->resolved_by_user_id,
                'occurred_at' => $occurredAt->toIso8601String(),
            ];

            $attributes += [
                'status' => 'unresolved',
                'resolved_at' => null,
                'resolved_by_user_id' => null,
            ];
        }

        $group->increment('total_count', 1, $attributes);

        if ($regression !== null) {
            $group->comments()->create([
                'user_id' => null,
                'type' => 'regression',
                'body' => 'Reopened automatically: the error occurred again after it was resolved.',
                'meta' => $regression,
            ]);

            return 'regression';
        }

        return null;
    }

    /**
     * A scalar as a string cut to the column length; null for non-scalars and empty values.
     */
    private function limitedString(mixed $value, int $length): ?string
    {
        if (! is_scalar($value) || $value === '') {
            return null;
        }

        return mb_substr((string) $value, 0, $length);
    }

    /**
     * A JSON column value as sent, or the overflow (a marker by default) when its encoding exceeds the configured limit.
     *
     * @param  array<mixed>|null  $overflow
     */
    private function boundedBlob(mixed $value, ?array $overflow = null): mixed
    {
        $bytes = strlen((string) json_encode($value));

        return $bytes <= config('watch.ingest.max_blob_bytes') ? $value : ($overflow ?? ['_truncated' => true, 'bytes' => $bytes]);
    }

    /**
     * The route methods as one upper-case string such as `GET|HEAD`: a list is joined with a pipe, dropping entries
     * that are not non-empty strings. Null when nothing is left.
     */
    private function routeMethodsOrNull(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode('|', array_filter($value, fn (mixed $method): bool => is_string($method) && $method !== ''));
        }

        $methods = $this->limitedString($value, 100);

        return $methods === null ? null : strtoupper($methods);
    }

    /**
     * Milliseconds for a `decimal(12,3)` column: null unless the value is a finite number, negatives become zero and
     * huge values are held at the column maximum.
     */
    private function fractionalMsOrNull(mixed $value): ?float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return null;
        }

        return min(round(max(0.0, (float) $value), 3), 999_999_999.999);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeLog(Project $project, array $data): void
    {
        $trace = $this->resolveTrace($project, $data);

        $context = $data['context'] ?? null;
        if ($context === [] || $context === '') {
            $context = null;
        }

        LogEntry::create([
            'project_id' => $project->id,
            'trace_id' => $trace?->id,
            'level' => $this->limitedString($data['level'] ?? null, 20) ?? 'info',
            'message' => $this->stringOrNull($data['message'] ?? null) ?? '',
            'source_type' => $this->limitedString($data['source_type'] ?? null, 50),
            'source_label' => $this->limitedString($data['source_label'] ?? null, 255),
            'user_name' => $this->limitedString($data['user_name'] ?? null, 255),
            'context' => is_array($context) ? $this->boundedBlob($context) : null,
            'environment' => $this->limitedString($data['environment'] ?? null, 50),
            'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? null),
        ]);
    }

    /**
     * Find the project's trace for a child record, creating a stub when the request record has not been ingested yet.
     *
     * Records are processed by independent queue jobs, so a child can arrive before its request. The stub is
     * flagged `is_stub` and carries placeholder request fields until `storeRequest` fills it in. Creation goes
     * through `firstOrCreate`, which catches the unique violation on (project_id, correlation_id) raised when a
     * concurrent job creates the same stub first and returns that row instead.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveTrace(Project $project, array $data): ?Trace
    {
        $correlationId = $this->limitedString($data['request_id'] ?? $data['correlation_id'] ?? null, 255);

        if (! $correlationId) {
            return null;
        }

        return Trace::firstOrCreate(
            ['project_id' => $project->id, 'correlation_id' => $correlationId],
            fn (): array => [
                'method' => '',
                'uri' => '',
                'is_stub' => true,
                'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? null),
            ],
        );
    }

    private function detectQueryType(string $sql): ?string
    {
        $trimmed = ltrim($sql);

        if ($trimmed === '') {
            return null;
        }

        $first = strtoupper(strtok($trimmed, " \t\n"));

        return in_array($first, ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true)
            ? $first
            : null;
    }

    /**
     * Timestamps more than a minute ahead are held there, so a far-future record still ages out of retention.
     */
    private function parseTimestamp(mixed $value, bool $allowNull = false, bool $allowFuture = false): ?CarbonImmutable
    {
        if (! $value) {
            return $allowNull ? null : CarbonImmutable::now();
        }

        try {
            $timestamp = CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return $allowNull ? null : CarbonImmutable::now();
        }

        return $allowFuture ? $timestamp : $timestamp->min(CarbonImmutable::now()->addMinute());
    }

    /**
     * An integer for a signed `int` column, or null when the value is not numeric or out of range.
     */
    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) && abs((float) $value) <= 2_147_483_647 ? (int) $value : null;
    }

    /**
     * An integer for an unsigned column, or null when the value is not numeric or falls outside 0..$max, which
     * would otherwise fail the insert under MySQL strict mode.
     */
    private function unsignedIntOrNull(mixed $value, int $max = 4_294_967_295): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return $number >= 0 && $number <= $max ? (int) $number : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    /**
     * A boolean for a nullable flag column, or null when the value is missing or not a scalar that reads as one.
     */
    private function boolOrNull(mixed $value): ?bool
    {
        return is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;
    }

    private function redactSensitiveHeaders(mixed $headers): mixed
    {
        if (! is_array($headers)) {
            return $headers;
        }

        foreach ($headers as $name => $value) {
            if (is_string($name) && preg_match('/^(authorization|proxy-authorization|cookie|set-cookie|x-api-key|x-csrf-token|x-xsrf-token)$/i', $name)) {
                $headers[$name] = '[redacted]';
            }
        }

        return $headers;
    }
}
