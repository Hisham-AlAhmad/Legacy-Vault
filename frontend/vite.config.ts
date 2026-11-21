/// <reference types="vitest/config" />
import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// Static-buildable SPA — ships as static assets, no Node runtime in production
// (docs/10_Frontend_Architecture.md §10).
export default defineConfig(({ mode }) => {
  // Where the Laravel dev server listens. Set API_PROXY_TARGET in frontend/.env
  // (git-ignored) or as a real env var, which wins over the file.
  const env = loadEnv(mode, process.cwd(), '');
  const apiTarget = process.env.API_PROXY_TARGET ?? env.API_PROXY_TARGET ?? 'http://localhost:8000';

  return {
    plugins: [react(), tailwindcss()],
    server: {
      port: 5173,
      // Proxy API + CSRF-cookie endpoints to the Laravel dev server so cookies are
      // first-party in development (docs/05_Security_Architecture.md §4).
      proxy: {
        '/api': apiTarget,
        '/sanctum': apiTarget,
      },
    },
    test: {
      environment: 'node', // WebCrypto is available on globalThis in Node 20+
      include: ['src/**/*.test.ts', 'src/**/*.test.tsx'],
    },
  };
});
