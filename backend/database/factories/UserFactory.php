<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => static::$password ??= Hash::make('correct horse battery staple 9!'),
            'status' => User::STATUS_ACTIVE,
            'locale' => 'en',
            'recovery_key_acknowledged_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => User::STATUS_PENDING,
            'recovery_key_acknowledged_at' => null,
        ]);
    }
}
