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

const tomlPath = join(here, '..', 'shopify.app.toml');

const port = process.env.BACKEND_PORT || process.env.PORT || 8000;
const host = process.env.BACKEND_HOST || '127.0.0.1';
const tunnel = process.env.HOST || process.env.APP_URL || '';

// The CLI injects SCOPES once, when `shopify app dev` STARTS, and that value is
// what builds the OAuth consent URL. Editing shopify.app.toml afterwards does
// not reach this process, and neither does restarting the backend - the value
// lives in the parent CLI process. So a scope added to the toml mid-session is
// requested by nobody: the consent screen appears, the merchant accepts, and
// the grant comes back with the OLD scope set. On 3 Sep 2026 that cost a full
// uninstall-and-reinstall of the app to discover, because everything about it
// looks like it worked - `read_markets` was in the toml, in web/.env, and in the
// deployed app version, and simply absent from the session it granted.
function checkScopes() {
  const injected = process.env.SCOPES || '';

  if (injected === '') {
    return;
  }

  let declared;

  try {
    const match = readFileSync(tomlPath, 'utf8').match(/^\s*scopes\s*=\s*"([^"]*)"/m);

    if (!match) {
      return;
    }

    declared = match[1];
  } catch {
    // No toml to compare against: nothing useful to say.
    return;
  }

  const asSet = (value) =>
    new Set(
      value
        .split(',')
        .map((scope) => scope.trim())
        .filter(Boolean),
    );

  const declaredSet = asSet(declared);
  const injectedSet = asSet(injected);
  const missing = [...declaredSet].filter((scope) => !injectedSet.has(scope));
  const extra = [...injectedSet].filter((scope) => !declaredSet.has(scope));

  if (missing.length === 0 && extra.length === 0) {
    return;
  }

  console.warn('');
  console.warn('[oxford-staging] SCOPE MISMATCH - OAuth will request the wrong scopes.');
  console.warn(`[oxford-staging]   shopify.app.toml declares: ${[...declaredSet].sort().join(',')}`);
  console.warn(`[oxford-staging]   this dev session injected:  ${[...injectedSet].sort().join(',')}`);

  if (missing.length > 0) {
    console.warn(`[oxford-staging]   MISSING from the running session: ${missing.sort().join(',')}`);
  }

  if (extra.length > 0) {
    console.warn(`[oxford-staging]   only in the running session: ${extra.sort().join(',')}`);
  }

  console.warn('[oxford-staging] A grant made now would NOT include the missing scopes.');
  console.warn('[oxford-staging] Stop `shopify app dev` and start it again, then deploy and re-grant.');
  console.warn('');
}

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

// POS gives an extension no way to discover its own app URL (confirmed against
// the 2026-07 docs: no settings API, no relative resolution, no proxy), and UI
// extension builds have no environment-variable support - `process.env` works in
// dev and then throws "process is not defined" on the deployed version. So the
// tile compiles the URL in as a constant, and the one place that already knows
// the current tunnel is this file. Rewriting the constant here means a tunnel
// change needs no manual edit and no extension redeploy: the CLI rebuilds the
// extension on file change and POS picks it up when the tile is reopened.
//
// Only the value inside the quotes is touched, and only when it differs, so this
// does not churn the file on every dev start.
function writeExtensionAppUrl() {
  if (!tunnel.startsWith('https://')) {
    return;
  }

  const path = join(here, '..', 'extensions', 'loyalty-tile', 'src', 'lib', 'appUrl.js');
  const host = tunnel.replace(/\/+$/, '');

  let source;

  try {
    source = readFileSync(path, 'utf8');
  } catch {
    console.warn('[oxford-staging] No extensions/loyalty-tile/src/lib/appUrl.js; the POS tile will use whatever is compiled in.');

    return;
  }

  const pattern = /^(export const APP_URL = ')([^']*)(';)$/m;
  const match = source.match(pattern);

  if (!match) {
    console.warn('[oxford-staging] Could not find the APP_URL constant in appUrl.js; leaving it alone.');

    return;
  }

  if (match[2] === host) {
    return;
  }

  writeFileSync(path, source.replace(pattern, `$1${host}$3`), 'utf8');
  console.log(`[oxford-staging] POS tile APP_URL -> ${host}`);
  console.log('[oxford-staging] Reopen the Privilege Club tile on the device to pick it up.');
}

writeHandshake();
checkScopes();
writeExtensionAppUrl();

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
