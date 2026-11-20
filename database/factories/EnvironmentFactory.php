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
            ...Environment::apiKeyAttributes('pk_test_'.Str::random(40)),
        ];
    }

    public function withApiKey(string $key): static
    {
        return $this->state(fn () => Environment::apiKeyAttributes($key));
    }
}
