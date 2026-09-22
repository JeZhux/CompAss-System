# CompAss Frontend (React + Vite)

## Scripts

```powershell
npm install
npm run dev      # Vite on 127.0.0.1:3000, proxies /api + /sanctum → http://127.0.0.1:8000
npm run build    # production bundle → dist/ (untracked, rebuilt by Vercel)
npm run preview  # local preview on :3000 (proxy entries are local-only)
```

## API base modes (`frontend/.env.example`)

- Local (preferred): `VITE_API_BASE=` (empty) — same-origin relative via Vite dev proxy. Browser talks only to the Vite origin, so Sanctum XSRF + session cookies stay same-site (avoids `localhost:3000` vs `127.0.0.1:8000` CSRF mismatches).
- Vercel: `VITE_API_BASE=https://<backend-host>` — no trailing `/api` (client appends `/api`, `/sanctum`). Requires matching backend `SANCTUM_STATEFUL_DOMAINS` / `CORS_ALLOWED_ORIGINS` + `SESSION_SAME_SITE=none; Secure`.

`vite preview` proxy is local-only — production uses `VITE_API_BASE`, not the preview proxy.

## Fonts

- Current: self-hosted woff2 in `public/fonts` (7 latin-subset files: Fraunces display + IBM Plex Sans UI), preloaded in `index.html`, `@font-face` in `src/styles/tokens.css` — see `--font-display` / `--font-ui`. No Google Fonts CDN (offline + CSP hygiene).
