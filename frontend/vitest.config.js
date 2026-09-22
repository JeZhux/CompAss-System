import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

// Tiny suite: jsdom + JSX via the existing react plugin.
// Run: npm run test (aka vitest run). Deps install pending (Agent 4).
export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    include: ['src/**/*.test.jsx'],
  },
});
