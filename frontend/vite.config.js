import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// CompAss frontend — dev on :3000, API via same-origin proxy to Laravel.
//
/// Same-origin is the default (VITE_API_BASE empty): browser talks only to
// the Vite origin (localhost:3000 or 127.0.0.1:3000 — both work), Vite
// proxies /api + /sanctum → 127.0.0.1:8000. Sanctum XSRF + session cookies
// then stay same-site, so login and every mutating action pass CSRF.
// Direct-origin mode (VITE_API_BASE=http://127.0.0.1:8000 + credentials)
// remains supported but requires matching CORS/stateful-domain config and
// breaks when the UI is opened on the other loopback host.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 3000,
    strictPort: true,
    host: '127.0.0.1',
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
      '/sanctum': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  // NOTE (deploy): the preview proxy below is local-only — it lets
  // `vite preview` talk to a dev Laravel on :8000. Production on Vercel
  // serves the SPA statically (see vercel.json rewrites), so the browser
  // must use same-origin /api (VITE_API_BASE empty) pointed at the real
  // backend host — the preview proxy targets below must NOT be treated as
  // a production API route. Proxy targets intentionally unchanged.
  preview: {
    port: 3000,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
      '/sanctum': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
});
