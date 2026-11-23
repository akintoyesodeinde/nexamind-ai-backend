<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now('UTC'),
            'password' => static::$password ??= Hash::make('Password123!'),
            'job_title' => fake()->jobTitle(),
            'timezone' => 'UTC',
            'locale' => 'en-US',
            'preferences_json' => ['citations_expanded_by_default' => true],
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }
}
