/**
 * Where the till sends its requests.
 *
 * **POS gives an extension no way to discover its own app URL.** Confirmed
 * against the 2026-07 POS UI extensions documentation, whose own worked example
 * hardcodes `https://YOUR_DEVELOPMENT_SERVER/api/...`: there is no settings API
 * for POS, no relative-path resolution against `app_url`, and no proxy. The
 * value therefore has to be compiled in, and this constant is the one place it
 * lives.
 *
 * Native environment variables are not available either — support is an open
 * feature request, and `process.env` in an extension works in development and
 * then throws `ReferenceError: process is not defined` on the deployed version,
 * which is the worst possible shape of failure. So this is a plain constant that
 * `web/serve.mjs` rewrites at `shopify app dev` start from the tunnel the CLI
 * injected, using the same handshake it already keeps for `php artisan`. In
 * development you never touch it; the CLI rebuilds the extension on file change,
 * and no redeploy of the extension is needed.
 *
 * **This is a dev tunnel URL today and MUST be set to the production URL at
 * release.** That is on the go-live checklist next to the same requirement for
 * `application_url` in `shopify.app.toml`, which this deliberately mirrors.
 */
export const APP_URL = 'https://arrive-weddings-setting-graham.trycloudflare.com';

/**
 * The base URL for this run, preferring anything the host offers.
 *
 * `hostGlobal` is the POS `shopify` global. Nothing in the POS surface supplies
 * an app URL today — `StandardApi` composes locale, toast, session, print,
 * product search, device, connectivity, storage and pin pad, and no
 * `EnvironmentApi` — but the surface carries a `{[key: string]: any}` index
 * signature, so reading a property that does not exist is silent rather than a
 * type error. That is exactly how `shopify.environment?.appUrl` survived: it was
 * always `undefined`, always fell back to `''`, and never once complained.
 *
 * So the host is still consulted, because if a future API version does supply
 * one it should win over a compiled constant — but only when it is a usable
 * absolute https origin, and the constant is the floor. **This function must
 * never return an empty string.** The whole defect was an empty base becoming a
 * relative URL, and `TillApi` now refuses one; returning the constant means the
 * refusal is reserved for a genuine misconfiguration.
 */
export function resolveAppUrl(hostGlobal = undefined) {
  const offered = hostGlobal?.environment?.appUrl;

  if (typeof offered === 'string' && /^https:\/\/[^/\s]+\/?$/.test(offered)) {
    return offered.replace(/\/$/, '');
  }

  return APP_URL;
}
