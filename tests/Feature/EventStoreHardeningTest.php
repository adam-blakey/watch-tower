<?php

use App\Models\CacheEvent;
use App\Models\ErrorOccurrence;
use App\Models\LogEntry;
use App\Models\OutgoingRequest;
use App\Models\Project;
use App\Models\QueueJobRun;
use App\Models\ScheduledTaskRun;
use App\Models\Trace;
use App\Watch\EventStore;
use App\Watch\Fingerprinter;

beforeEach(function () {
    $this->store = new EventStore(new Fingerprinter);
    $this->project = Project::factory()->create();
});

it('truncates over-long strings to the column length', function (string $type, string $field, string $model, string $column, int $length) {
    $this->store->store($this->project, ['type' => $type, 'data' => ['request_id' => 'req-1', $field => str_repeat('x', $length + 50)]]);

    expect(mb_strlen($model::where('project_id', $this->project->id)->sole()->{$column}))->toBe($length);
})->with([
    'request method' => ['request', 'method', Trace::class, 'method', 10],
    'request environment' => ['request', 'environment', Trace::class, 'environment', 50],
    'request ip address' => ['request', 'ip_address', Trace::class, 'ip_address', 45],
    'cache key' => ['cache-event', 'key', CacheEvent::class, 'key', 255],
    'cache operation' => ['cache-event', 'operation', CacheEvent::class, 'operation', 20],
    'outgoing url' => ['outgoing-request', 'url', OutgoingRequest::class, 'url', 2048],
    'log level' => ['log', 'level', LogEntry::class, 'level', 20],
    'job queue' => ['job', 'queue', QueueJobRun::class, 'queue', 100],
    'job status' => ['job', 'status', QueueJobRun::class, 'status', 50],
    'scheduled task name' => ['scheduled-task', 'task', ScheduledTaskRun::class, 'task', 255],
]);

it('does not throw when scalar fields carry arrays or absurd numbers', function (string $type, mixed $value) {
    $fields = ['method', 'uri', 'status_code', 'user_id', 'user_name', 'user_email', 'duration_ms', 'db_queries_count',
        'memory_used_kb', 'memory_peak_kb', 'environment', 'release', 'hostname', 'ip_address', 'user_agent', 'sql',
        'connection', 'row_count', 'n_plus_one_group', 'class', 'message', 'file', 'line', 'trace', 'job_id', 'queue',
        'status', 'attempts', 'key', 'store', 'operation', 'command', 'exit_code', 'task', 'task_hash', 'schedule',
        'threshold_ms', 'mailer', 'subject', 'recipients_count', 'attachments_count', 'channel', 'host', 'url',
        'request_size_bytes', 'response_size_bytes', 'level', 'name', 'email', 'fired_by', 'listeners_count', 'output',
        'occurred_at', 'next_run_at'];

    $this->store->store($this->project, ['type' => $type, 'data' => ['request_id' => 'req-1', ...array_fill_keys($fields, $value)]]);
})->with(EventStore::TYPES)->with([
    'array' => [['nested' => 'value']],
    'huge number' => [1e30],
    'negative number' => [-5],
])->throwsNoExceptions();

it('replaces an oversized blob with a marker and keeps small ones', function () {
    config(['watch.ingest.max_blob_bytes' => 100]);

    $this->store->store($this->project, ['type' => 'request', 'data' => [
        'request_id' => 'req-1',
        'headers' => ['x-big' => str_repeat('a', 200)],
        'request_data' => ['small' => true],
    ]]);

    $trace = Trace::sole();

    expect($trace->headers)->toBe(['_truncated' => true, 'bytes' => strlen(json_encode(['x-big' => str_repeat('a', 200)]))])
        ->and($trace->request_data)->toBe(['small' => true]);
});

it('stores an empty stacktrace instead of a marker when the stacktrace is oversized', function () {
    config(['watch.ingest.max_blob_bytes' => 100]);

    $this->store->store($this->project, ['type' => 'exception', 'data' => [
        'class' => 'RuntimeException',
        'trace' => [['file' => str_repeat('a', 200), 'line' => 1]],
    ]]);

    expect(ErrorOccurrence::sole()->stacktrace)->toBe([]);
});

it('clamps a future occurred_at but keeps a future next_run_at', function () {
    $this->store->store($this->project, ['type' => 'scheduled-task', 'data' => [
        'task' => 'inspire',
        'occurred_at' => '2099-01-01T00:00:00Z',
        'next_run_at' => '2099-01-01T00:00:00Z',
    ]]);

    $run = ScheduledTaskRun::sole();

    expect($run->occurred_at->lessThanOrEqualTo(now()->addMinute()))->toBeTrue()
        ->and($run->next_run_at->year)->toBe(2099);
});
