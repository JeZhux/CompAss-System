> DEV ONLY — never seed in production, password123 must never exist in prod.

# DEV/TEST ONLY — never seed or use in production

Realistic fixture accounts seeded by `Database\Seeders\DemoSeeder` into the **dev database only** (`compass`, `APP_ENV=local`): one admin, one Grade 7 Science teacher (7-Rizal), and three learners with a full term journey (lab report + Quiz 1 + Practice Drill 1). Never run this seeder against production. All accounts share the fixture password below and have `must_change_password=false`.

CompAss-ID rework (Phase A): every role logs in with its server-generated `school_id` (`ADM-`/`TEA-`/`STU-XXXX-XXXXX`). Email is removed — no email login exists.

| # | Role | Name | Login identifier (school_id) | Password | must_change_password |
|---|------|------|------------------------------|----------|----------------------|
| 1 | Admin | Maria Santos | `ADM-7100-00001` | `password123` | false |
| 2 | Teacher | Jose Ramos | `TEA-7100-00001` | `password123` | false |
| 3 | Student (strong) | Ana Reyes | `STU-7100-00001` | `password123` | false |
| 4 | Student (average) | Mark Dela Cruz | `STU-7100-00002` | `password123` | false |
| 5 | Student (struggling) | Liza Mendoza | `STU-7100-00003` | `password123` | false |

Notes:
- All roles log in with `school_id` (CompAss ID) via `POST /api/auth/login {identifier, password}`; identifier is trimmed, case-sensitive.
- Liza (`STU-7100-00003`) opted into class photos (`photo_opt_in=true`); the other two did not.
- Expected Quiz 1 outcomes: Ana 9/10 (all Mastered), Mark 6.5/10 (SCI7-03 Mastered), Liza 3/10 (all Not_Mastered). Liza has not opened Practice Drill 1 (shows as not-started).
- No password hashes are included in this file — the shared fixture password is `password123`.
