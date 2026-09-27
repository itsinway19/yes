<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function newModel(array $attributes = []): User
    {
        return parent::newModel($attributes)->setConnection('pgsql_authority');
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            $current = $user->hasVerifiedEmail();
            DB::connection('pgsql_authority')->table('principal_lifecycles')->insert([
                'user_id' => $user->id,
                'status' => $current ? 'current' : 'pending',
                'generation' => $current ? 1 : 0,
                'cause' => $current ? null : 'primary_email_unverified',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

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
}
