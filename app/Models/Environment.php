<?php

namespace App\Models;

use App\Models\Concerns\UsesAppDatabase;
use Database\Factories\EnvironmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable([
    'project_id',
    'name',
    'slug',
])]
#[Hidden(['api_key_hash', 'previous_api_key_hash'])]
class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use HasFactory, HasUuids, UsesAppDatabase;

    public const DEFAULT_NAME = 'Production';

    public const DEFAULT_SLUG = 'production';

    public const API_KEY_PREFIX_LENGTH = 10;

    public const API_KEY_LAST_USED_THROTTLE_MINUTES = 5;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'api_key_version' => 1,
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('notDeleting', function (Builder $query): void {
            $query->whereNull($query->getModel()->qualifyColumn('deleting_at'));
        });
    }

    protected function casts(): array
    {
        return [
            'deleting_at' => 'datetime',
            'api_key_version' => 'integer',
            'previous_api_key_expires_at' => 'datetime',
            'api_key_last_used_at' => 'datetime',
        ];
    }

    public static function generateApiKey(): string
    {
        return 'pk_'.Str::random(40);
    }

    public static function hashApiKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * @return array{api_key_hash: string, api_key_prefix: string}
     */
    public static function apiKeyAttributes(string $key): array
    {
        return [
            'api_key_hash' => self::hashApiKey($key),
            'api_key_prefix' => substr($key, 0, self::API_KEY_PREFIX_LENGTH),
        ];
    }

    public static function findByApiKey(string $key): ?self
    {
        $hash = self::hashApiKey($key);

        return static::query()
            ->where(function (Builder $query) use ($hash): void {
                $query->where('api_key_hash', $hash)
                    ->orWhere(function (Builder $previous) use ($hash): void {
                        $previous->where('previous_api_key_hash', $hash)
                            ->where('previous_api_key_expires_at', '>', now());
                    });
            })
            ->whereHas('project')
            ->with('project')
            ->first();
    }

    public function issueApiKey(): string
    {
        $key = self::generateApiKey();

        $this->forceFill(self::apiKeyAttributes($key));

        return $key;
    }

    public function rotateApiKey(int $gracePeriodSeconds = 0): string
    {
        $key = self::generateApiKey();
        $keepsPreviousKey = $gracePeriodSeconds > 0;

        $this->forceFill([
            ...self::apiKeyAttributes($key),
            'previous_api_key_hash' => $keepsPreviousKey ? $this->api_key_hash : null,
            'previous_api_key_expires_at' => $keepsPreviousKey ? now()->addSeconds($gracePeriodSeconds) : null,
            'api_key_version' => $this->api_key_version + 1,
        ])->save();

        return $key;
    }

    public function hasActivePreviousApiKey(): bool
    {
        return $this->previous_api_key_hash !== null
            && $this->previous_api_key_expires_at?->isFuture() === true;
    }

    public function apiKeyVersionFor(string $key): int
    {
        return hash_equals((string) $this->api_key_hash, self::hashApiKey($key))
            ? $this->api_key_version
            : $this->api_key_version - 1;
    }

    public function acceptsApiKeyVersion(?int $version): bool
    {
        if ($version === null) {
            return $this->api_key_version === 1;
        }

        return $version === $this->api_key_version
            || ($version === $this->api_key_version - 1 && $this->hasActivePreviousApiKey());
    }

    public function recordApiKeyUse(): void
    {
        $threshold = now()->subMinutes(self::API_KEY_LAST_USED_THROTTLE_MINUTES);

        if ($this->api_key_last_used_at !== null && $this->api_key_last_used_at->gt($threshold)) {
            return;
        }

        DB::table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->where(fn ($query) => $query->whereNull('api_key_last_used_at')->orWhere('api_key_last_used_at', '<=', $threshold))
            ->update(['api_key_last_used_at' => now()]);

        $this->setAttribute('api_key_last_used_at', now());
        $this->syncOriginalAttribute('api_key_last_used_at');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
