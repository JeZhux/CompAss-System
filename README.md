# CompAss

School assessment companion for admins, teachers, and students — Laravel API + React console.

> Note: `frontend/dist/` must stay untracked (see root `.gitignore`). Do not commit build output; it is rebuilt by Vercel / `npm run build`.

## Structure

- `backend/` — Laravel 13 + Sanctum API (PostgreSQL, session-cookie auth). See `backend/README.md`.
- `frontend/` — React 18 + Vite console (port 3000 dev, same-origin `/api` proxy). See `frontend/README.md`.

## Quickstart (dev)

```powershell
# backend
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed   # BootstrapAdmin only when BOOTSTRAP_ADMIN_* set; never DemoSeeder in prod
php artisan test

# frontend (new shell)
cd frontend
npm install
npm run dev   # VITE_API_BASE empty = same-origin proxy to 127.0.0.1:8000
```

Demo fixture accounts (dev only): see `DEMO_USERS.md` — never seed in production.

## Pilot deploy map

- Frontend → **Vercel** (`frontend/`), `VITE_API_BASE=https://<backend-host>` (no trailing `/api`).
- Backend → **Render / Railway** (Laravel, `APP_ENV=production`), session cookies `Secure + Encrypt + SameSite=none`.
- Database + Storage → **Supabase Postgres + Supabase S3** (`DB_HOST=db.xxx.supabase.co`, `DB_SSLMODE=require`, `FILESYSTEM_DISK=s3`, private bucket `compass-private`, keys backend-only).
- Prod env template: `backend/.env.production.example`. Mail stays `MAIL_MAILER=log` (admin handoff; swap to `smtp` if needed).

## Docs

- `backend/README.md`, `frontend/README.md`
- Architecture (Approved): `ArchitectureOverview.md` (ARCH-001), `RequirementsQualityAttributes.md` (ARCH-002), `ArchitectureDecisionRecords.md` (ARCH-003), `DataArchitecture.md` (ARCH-004), `ApiEventContracts.md` (ARCH-005), `DeploymentSecurityArchitecture.md` (ARCH-006)
