<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Role states (Plan 06 Phase 4b design §10 "Fixtures / harness": "user
     * factories per role (`owner()`, `member()`, `viewer()`)").
     */
    public function owner(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Owner]);
    }

    public function member(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Member]);
    }

    public function viewer(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Viewer]);
    }
}
