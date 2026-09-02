// Cross-platform launcher used by `shopify app dev`.
// The Shopify CLI assigns the backend port via BACKEND_PORT / PORT; Laravel's
// `artisan serve` needs it as a flag.
//
// This process is also the ONLY place the CLI's injected environment is
// visible: HOST (the tunnel URL, new on every dev restart), SHOPIFY_API_KEY,
// SHOPIFY_API_SECRET and SCOPES. A plain `php artisan` shell inherits none of
// it, which used to make `shopify:webhooks --register` refuse with app URL
// http://localhost:8000. So we hand it on in a handshake file that
// App\Lib\DevTunnel reads, and delete it when the CLI stops, because the
// tunnel dies with it and a stale URL is worse than none.
import { spawn } from 'node:child_process';
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const handshakePath = join(here, 'storage', 'app', 'dev-tunnel.json');

const port = process.env.BACKEND_PORT || process.env.PORT || 8000;
const host = process.env.BACKEND_HOST || '127.0.0.1';
const tunnel = process.env.HOST || process.env.APP_URL || '';

function writeHandshake() {
  if (!tunnel.startsWith('https://')) {
    console.warn('[oxford-staging] No tunnel URL in HOST; webhook registration from a plain terminal will not work.');
    return;
  }

  mkdirSync(dirname(handshakePath), { recursive: true });
  writeFileSync(
    handshakePath,
    JSON.stringify(
      {
        host: tunnel.replace(/\/+$/, ''),
        api_key: process.env.SHOPIFY_API_KEY || '',
        api_secret: process.env.SHOPIFY_API_SECRET || '',
        scopes: process.env.SCOPES || '',
        backend_port: String(port),
        pid: process.pid,
        started_at: new Date().toISOString(),
      },
      null,
      2,
    ),
    // Owner-only: this carries the app's API secret.
    { encoding: 'utf8', mode: 0o600 },
  );

  console.log(`[oxford-staging] Tunnel ${tunnel} recorded for artisan -> storage/app/dev-tunnel.json`);
}

// Only remove the handshake if it is still ours. The CLI starts a replacement
// backend before it stops the old one, so an unconditional delete here races
// the new process and removes the file it just wrote - leaving a live tunnel
// with no handshake, which is the exact state this is supposed to prevent.
function clearHandshake() {
  try {
    if (JSON.parse(readFileSync(handshakePath, 'utf8')).pid !== process.pid) {
      return;
    }

    rmSync(handshakePath, { force: true });
  } catch {
    // Absent, half-written or unreadable: nothing of ours to clean up.
  }
}

writeHandshake();

console.log(`[oxford-staging] Laravel backend -> http://${host}:${port}`);

const child = spawn('php', ['artisan', 'serve', `--host=${host}`, `--port=${port}`], {
  stdio: 'inherit',
  shell: true,
});

// Ctrl+C on Windows does not always reach an 'exit' handler, so cover the
// signals too. rmSync is idempotent, so running twice is harmless.
process.on('exit', clearHandshake);
for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP', 'SIGBREAK']) {
  process.on(signal, () => {
    clearHandshake();
    child.kill();
    process.exit(0);
  });
}

child.on('exit', (code) => {
  clearHandshake();
  process.exit(code ?? 0);
});
