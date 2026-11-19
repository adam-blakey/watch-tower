<?php

namespace App\Notifications;

use App\Models\ErrorGroup;
use App\Models\ErrorOccurrence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class IssueRaised extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public ErrorGroup $group,
        public ErrorOccurrence $occurrence,
        public bool $isRegression = false,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->group->project;
        $exceptionClass = Str::limit($this->group->exception_class, 120);
        $heading = $this->isRegression ? 'Issue reopened' : 'New issue';
        $route = $this->occurrence->trace?->uri !== null
            ? trim($this->occurrence->trace->method.' '.$this->occurrence->trace->uri)
            : null;

        $mail = (new MailMessage)
            ->subject("[{$project->name}] {$heading}: {$exceptionClass}")
            ->greeting("{$heading} in {$project->name}")
            ->line($this->isRegression
                ? 'An issue that was resolved has occurred again and has been reopened.'
                : 'A new issue has been raised.')
            ->line("Exception: {$exceptionClass}")
            ->line('Message: '.Str::limit($this->group->first_message ?? '(no message)', 300))
            ->line('First seen: '.$this->group->first_occurrence_at?->format('Y-m-d H:i:s T'));

        if ($this->occurrence->environment !== null) {
            $mail->line("Environment: {$this->occurrence->environment}");
        }

        if ($route !== null) {
            $mail->line('Route: '.Str::limit($route, 200));
        }

        return $mail->action('View issue', route('projects.issues.show', [$project, $this->group->display_number]));
    }
}
