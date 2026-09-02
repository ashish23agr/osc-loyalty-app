import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

/**
 * Kept apart from vite.config.js because that file throws without
 * SHOPIFY_API_KEY on a build, and a test run has no API key and needs none.
 */
// Dates are rendered in the browser's timezone, so the suite pins one. Europe/
// London is the programme's reporting timezone and OSC's only trading region,
// so it is also what a real console will be showing.
process.env.TZ = 'Europe/London';

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./tests/setup.js'],
    include: ['tests/**/*.test.{js,jsx}'],
    restoreMocks: true,
  },
});
