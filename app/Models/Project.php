<?php

namespace App\Models;

use App\Models\Concerns\UsesAppDatabase;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organization_id',
    'name',
    'slug',
    'description',
    'sampling_rate',
    'retention_days',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids, UsesAppDatabase;

    /**
     * Hide projects that are being deleted.
     *
     * `deleting_at` is set by the delete action and the queued
     * `DeleteProjectData` job removes the row once its telemetry is gone. The
     * `notDeleting` scope keeps the project out of every query in between:
     * lists, route binding, ingest authentication and queued events. The job
     * opts out with `Project::withoutGlobalScope('notDeleting')`.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('notDeleting', function (Builder $query): void {
            $query->whereNull($query->getModel()->qualifyColumn('deleting_at'));
        });
    }

    protected function casts(): array
    {
        return [
            'sampling_rate' => 'float',
            'retention_days' => 'integer',
            'deleting_at' => 'datetime',
        ];
    }

    public function createDefaultEnvironment(): string
    {
        $environment = $this->environments()->make([
            'name' => Environment::DEFAULT_NAME,
            'slug' => Environment::DEFAULT_SLUG,
            'is_default' => true,
        ]);
        $apiKey = $environment->issueApiKey();
        $environment->save();

        return $apiKey;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * @return HasMany<Environment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    /**
     * @return HasOne<Environment, $this>
     */
    public function defaultEnvironment(): HasOne
    {
        return $this->hasOne(Environment::class)->where('is_default', true);
    }

    /**
     * @return HasMany<Trace, $this>
     */
    public function traces(): HasMany
    {
        return $this->hasMany(Trace::class);
    }

    /**
     * @return HasMany<ErrorGroup, $this>
     */
    public function errorGroups(): HasMany
    {
        return $this->hasMany(ErrorGroup::class);
    }

    /**
     * @return HasMany<ErrorOccurrence, $this>
     */
    public function errorOccurrences(): HasMany
    {
        return $this->hasMany(ErrorOccurrence::class);
    }

    /**
     * @return HasMany<TraceQuery, $this>
     */
    public function traceQueries(): HasMany
    {
        return $this->hasMany(TraceQuery::class);
    }

    /**
     * @return HasMany<EventOccurrence, $this>
     */
    public function eventOccurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class);
    }

    /**
     * @return HasMany<QueueJobRun, $this>
     */
    public function queueJobRuns(): HasMany
    {
        return $this->hasMany(QueueJobRun::class);
    }

    /**
     * @return HasMany<CommandRun, $this>
     */
    public function commandRuns(): HasMany
    {
        return $this->hasMany(CommandRun::class);
    }

    /**
     * @return HasMany<ScheduledTaskRun, $this>
     */
    public function scheduledTaskRuns(): HasMany
    {
        return $this->hasMany(ScheduledTaskRun::class);
    }
}
