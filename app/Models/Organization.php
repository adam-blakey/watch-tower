<?php

namespace App\Models;

use App\Models\Concerns\UsesAppDatabase;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'plan', 'retention_days'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids, UsesAppDatabase;

    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
        ];
    }

    /**
     * The oldest organization, created on first use so a fresh install never has none.
     */
    public static function default(): self
    {
        return static::query()->orderBy('created_at')->first()
            ?? static::create(['name' => 'Default', 'slug' => 'default', 'plan' => 'free', 'retention_days' => 30]);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
