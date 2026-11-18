<?php

namespace Database\Factories;

use App\Models\Environment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Environment>
 */
class EnvironmentFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->word();

        return [
            'project_id' => Project::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'is_default' => false,
            ...Environment::apiKeyAttributes('pk_test_'.Str::random(40)),
        ];
    }

    /**
     * The environment every project starts with.
     */
    public function default(): static
    {
        return $this->state(fn () => [
            'name' => Environment::DEFAULT_NAME,
            'slug' => Environment::DEFAULT_SLUG,
            'is_default' => true,
        ]);
    }

    /**
     * Give the environment a known plain-text API key for testing. Only its
     * hash and display prefix are stored, as for a real environment.
     */
    public function withApiKey(string $key): static
    {
        return $this->state(fn () => Environment::apiKeyAttributes($key));
    }
}
