<?php

namespace Database\Factories;

use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'organization_id' => Organization::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'description' => fake()->sentence(),
            'sampling_rate' => 1.0,
            'retention_days' => 30,
        ];
    }

    /**
     * Give every created project its default environment, as creating one in Settings does.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Project $project): void {
            Environment::factory()->for($project)->default()->create();
        });
    }

    /**
     * Give the project's default environment a known plain-text API key for
     * testing. Only its hash and display prefix are stored, as for a real key.
     */
    public function withApiKey(string $key): static
    {
        return $this->afterCreating(function (Project $project) use ($key): void {
            $project->defaultEnvironment->forceFill(Environment::apiKeyAttributes($key))->save();
        });
    }
}
