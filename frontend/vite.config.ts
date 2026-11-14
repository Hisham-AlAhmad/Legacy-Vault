/// <reference types="vitest/config" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// Static-buildable SPA — ships as static assets, no Node runtime in production
// (docs/10_Frontend_Architecture.md §10).
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    // Proxy API + CSRF-cookie endpoints to the Laravel dev server so cookies are
    // first-party in development (docs/05_Security_Architecture.md §4).
    proxy: {
      '/api': 'http://localhost:8000',
      '/sanctum': 'http://localhost:8000',
    },
  },
  test: {
    environment: 'node', // WebCrypto is available on globalThis in Node 20+
    include: ['src/**/*.test.ts'],
  },
});
