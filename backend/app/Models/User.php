<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'school_id'])]
#[Hidden(['password_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;

    use HasFactory;
    use Notifiable;

    /**
     * CompAss ID format: <ROLE>-<4 digits>-<5 digits> (e.g. STU-6181-30913).
     * Prefix must match role; full string globally unique; server-side only.
     */
    public const COMPASS_ID_PATTERN = '/^(ADM|TEA|STU)-[0-9]{4}-[0-9]{5}$/';

    /** Role => CompAss ID prefix. */
    public const ROLE_PREFIXES = [
        'Admin' => 'ADM',
        'Teacher' => 'TEA',
        'Student' => 'STU',
    ];

    /** Collision-retry budget for generateUniqueCompassId(). */
    public const COMPASS_ID_MAX_ATTEMPTS = 50;

    /**
     * Generate a random CompAss ID for the given role (format only, no
     * uniqueness check — use generateUniqueCompassId() for persistence).
     */
    public static function generateCompassId(string $role): string
    {
        if (! isset(self::ROLE_PREFIXES[$role])) {
            throw new \InvalidArgumentException('Unknown role for CompAss ID generation.');
        }

        return sprintf(
            '%s-%04d-%05d',
            self::ROLE_PREFIXES[$role],
            random_int(0, 9999),
            random_int(0, 99999)
        );
    }

    /**
     * Generate a globally-unique CompAss ID for the given role, retrying on
     * collision. Server-side generation only — callers never supply IDs.
     */
    public static function generateUniqueCompassId(string $role, int $maxAttempts = self::COMPASS_ID_MAX_ATTEMPTS): string
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $candidate = self::generateCompassId($role);

            if (! self::where('school_id', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not generate a unique CompAss ID.');
    }

    /**
     * Get the password field name for authentication.
     *
     * The ARCH-004 §4.1 column is `password_hash` (not the Laravel default `password`).
     * This override lets Laravel's auth guard and Sanctum resolve credentials
     * against the correct column.
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'photo_opt_in' => 'boolean',
        ];
    }

    /**
     * Classrooms this user teaches. Teacher scope derives from classrooms
     * (teacher_id + subject_id + school_year) since the Semester restructure
     * removed the old per-section join and its teacher assignments.
     */
    public function classrooms()
    {
        return $this->hasMany(Classroom::class, 'teacher_id');
    }

    /**
     * Convenience accessor: the API spec (§3.1.2) exposes account status as a
     * `status` string rather than the internal `is_active` boolean.
     */
    public function getStatusAttribute(): string
    {
        return $this->is_active ? 'active' : 'inactive';
    }
}
