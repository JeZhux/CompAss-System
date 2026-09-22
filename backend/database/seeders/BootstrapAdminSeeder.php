<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class BootstrapAdminSeeder extends Seeder
{
    /**
     * Seed the environment-driven provisioning admin account (ARCH-006 §5.1).
     *
     * Reads BOOTSTRAP_ADMIN_NAME / BOOTSTRAP_ADMIN_PASSWORD from
     * config/services.php. When name or password is missing/empty the seeder
     * is a NO-OP, so testing environments and fresh clones without the vars
     * are unaffected.
     *
     * CompAss-ID rework: the school_id is always server-generated
     * (ADM-<4 digits>-<5 digits>) — never supplied via env. The account is
     * created idempotently by name + role, and the generated CompAss ID is
     * printed to the console so the deployer can record it (it is the login
     * identifier).
     *
     * The account carries is_active=true and must_change_password=true so the
     * first login is forced through the password-change flow (BASELINE v1.2 §8
     * capability 1, ARCH-002 FR-038; enforced by AuthService::isPasswordChangeRequired).
     *
     * Note: The User model applies the 'hashed' cast on password_hash, so the
     * plain-text value is automatically hashed on save. Passing Hash::make()
     * here would cause a double-hash (ARCH-002 QA-004).
     *
     * @Traced-To ARCH-006 §5.1 (remediation-requirements.md), ARCH-002 FR-038, QA-004 (SDS)
     */
    public function run(): void
    {
        $name = config('services.bootstrap_admin.name');
        $password = config('services.bootstrap_admin.password');

        if (empty($name) || empty($password)) {
            return;
        }

        $existing = User::where('name', $name)->where('role', 'Admin')->first();

        if ($existing !== null) {
            return;
        }

        $user = new User();
        $user->name = $name;
        $user->school_id = User::generateUniqueCompassId('Admin');
        $user->password_hash = $password;
        $user->role = 'Admin';
        $user->is_active = true;
        $user->must_change_password = true;
        $user->save();

        $this->command?->info("Bootstrap admin '{$name}' CompAss ID: {$user->school_id}");
    }
}
