<?php

use App\Models\ErrorGroup;
use App\Models\Project;
use App\Models\User;
use App\Notifications\IssueRaised;
use App\Watch\EventStore;
use App\Watch\Fingerprinter;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->store = new EventStore(new Fingerprinter);
    $this->project = Project::factory()->create();
    $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    $this->projectAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $this->projectAdmin->projects()->attach($this->project);
    $this->outsider = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $this->outsider->projects()->attach(Project::factory()->create());

    $this->ingest = function (string $occurredAt, string $message = 'boom'): void {
        $this->store->store($this->project, [
            'type' => 'exception',
            'data' => [
                'class' => 'RuntimeException',
                'file' => 'app/A.php',
                'line' => 5,
                'message' => $message,
                'environment' => 'production',
                'occurred_at' => $occurredAt,
            ],
        ], $this->project->environments()->value('id'));
    };
});

it('emails everyone who can see the project once when a new issue is raised', function () {
    ($this->ingest)('2026-03-01T10:00:00Z');
    ($this->ingest)('2026-03-01T10:05:00Z');

    Notification::assertSentToTimes($this->superAdmin, IssueRaised::class, 1);
    Notification::assertSentToTimes($this->projectAdmin, IssueRaised::class, 1);
    Notification::assertNotSentTo($this->outsider, IssueRaised::class);
    Notification::assertCount(2);
});

it('emails again when a resolved issue regresses, but not when an old event arrives late', function () {
    ($this->ingest)('2026-03-01T10:00:00Z');

    ErrorGroup::where('project_id', $this->project->id)->sole()
        ->forceFill(['status' => 'resolved', 'resolved_at' => '2026-03-02T09:00:00Z'])->save();

    ($this->ingest)('2026-03-01T11:00:00Z');
    Notification::assertSentToTimes($this->projectAdmin, IssueRaised::class, 1);

    ($this->ingest)('2026-03-05T12:30:00Z');
    ($this->ingest)('2026-03-05T12:31:00Z');

    Notification::assertSentToTimes($this->projectAdmin, IssueRaised::class, 2);
    Notification::assertSentTo($this->projectAdmin, IssueRaised::class, fn (IssueRaised $notification) => $notification->isRegression);
});

it('links to the issue and escapes the exception message', function () {
    ($this->ingest)('2026-03-01T10:00:00Z', '<script>alert(1)</script> [click me](https://evil.test) ![x](https://evil.test/x.png)');

    $group = ErrorGroup::where('project_id', $this->project->id)->sole();
    $html = null;

    Notification::assertSentTo($this->projectAdmin, function (IssueRaised $notification) use (&$html): bool {
        $html = (string) $notification->toMail($this->projectAdmin)->render();

        return true;
    });

    expect($html)
        ->toContain(route('projects.issues.show', [$this->project, $group->display_number]))
        ->toContain($this->project->name)
        ->toContain('RuntimeException')
        ->toContain('production')
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>')
        ->not->toContain('href="https://evil.test"')
        ->not->toContain('src="https://evil.test');
});
