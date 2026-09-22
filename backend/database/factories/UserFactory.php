<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * Enforce the CompAss-ID invariant at the fixture level so a factory user
     * is ALWAYS conforming, regardless of how the role is supplied: every
     * role identifies by a server-generated school_id whose prefix matches
     * the role (ADM/TEA/STU-<4 digits>-<5 digits>). Runs after final
     * attribute resolution, before the model is persisted.
     *
     * The check is the full COMPASS_ID_PATTERN plus a role-prefix match —
     * a weak prefix-only check would accept malformed IDs (wrong digit
     * counts) and cross-role IDs (e.g. a Teacher holding a STU- ID).
     */
    public function configure(): static
    {
        return $this->afterMaking(function (User $user): void {
            $expectedPrefix = User::ROLE_PREFIXES[$user->role] ?? null;
            $schoolId = (string) ($user->school_id ?? '');

            $conforming = $expectedPrefix !== null
                && preg_match(User::COMPASS_ID_PATTERN, $schoolId) === 1
                && str_starts_with($schoolId, $expectedPrefix . '-');

            if (! $conforming) {
                $user->school_id = User::generateUniqueCompassId($user->role);
            }
        });
    }

    public function definition(): array
    {
        $role = fake()->randomElement(['Admin', 'Teacher', 'Student']);

        return [
            'name' => fake()->name(),
            'school_id' => User::generateUniqueCompassId($role),
            'password_hash' => static::$password ??= 'password',
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
            'photo_opt_in' => false,
            'photo_url' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'Admin',
            'school_id' => User::generateUniqueCompassId('Admin'),
        ]);
    }

    public function teacher(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'Teacher',
            'school_id' => User::generateUniqueCompassId('Teacher'),
        ]);
    }

    public function student(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'Student',
            'school_id' => User::generateUniqueCompassId('Student'),
        ]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes) => [
            'must_change_password' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
