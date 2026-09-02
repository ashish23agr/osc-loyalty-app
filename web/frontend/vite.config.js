import { defineConfig } from 'vite';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';

const root = dirname(fileURLToPath(import.meta.url));

// `shopify app dev` injects SHOPIFY_API_KEY. Expose it to index.html so
// App Bridge can pick it up from the <meta name="shopify-api-key"> tag.
if (process.env.npm_lifecycle_event === 'build' && !process.env.CI && !process.env.SHOPIFY_API_KEY) {
  throw new Error(
    '\nThe frontend build needs an API key. Run it through the CLI ' +
      '(`shopify app build`) or set SHOPIFY_API_KEY=<your-client-id> first.\n'
  );
}
process.env.VITE_SHOPIFY_API_KEY = process.env.SHOPIFY_API_KEY ?? '';

// Everything the Laravel backend owns gets proxied to it.
const backend = {
  target: `http://127.0.0.1:${process.env.BACKEND_PORT ?? 8000}`,
  changeOrigin: false,
};

const host = process.env.HOST ? process.env.HOST.replace(/https?:\/\//, '') : 'localhost';

const hmr =
  host === 'localhost'
    ? { protocol: 'ws', host: 'localhost', port: 64999, clientPort: 64999 }
    : { protocol: 'wss', host, port: Number(process.env.FRONTEND_PORT), clientPort: 443 };

export default defineConfig({
  root,
  plugins: [react()],
  server: {
    host: 'localhost',
    port: Number(process.env.FRONTEND_PORT ?? 5173),
    hmr,
    allowedHosts: true,
    proxy: {
      '^/api(/|$)': backend,
      '^/up$': backend,
    },
  },
  build: {
    // Build straight into Laravel's public/ so a single origin serves
    // everything in production. No symlinks (Windows friendly).
    outDir: resolve(root, '../public'),
    emptyOutDir: false,
    assetsDir: 'assets',
  },
});
