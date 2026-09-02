/**
 * Talking to the Laravel backend.
 *
 * App Bridge (loaded from cdn.shopify.com in index.html) patches window.fetch so
 * same-origin requests automatically carry the Shopify session token in the
 * Authorization header. That is the whole of the authentication story on this
 * side: V1 confirmed it, and EnsureShopifySession / EnsureStaffRole on the
 * server remain the enforcers. Nothing here plumbs a token by hand, and nothing
 * here decides what a role may do.
 */

/**
 * A refusal from the admin API, in the shape plan section 5.2 fixes:
 * { error: { code, message, details } }.
 *
 * Screens branch on `code`, never on the message, because the message is
 * wording and the code is the contract.
 */
export class ApiError extends Error {
  constructor(status, code, message, details = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.details = details;
  }

  /** Nobody has given this person a role yet — a first-run state, not a fault. */
  get isRoleMissing() {
    return this.code === 'no_role_assigned';
  }

  get isForbidden() {
    return this.status === 403;
  }

  get isNotFound() {
    return this.status === 404;
  }
}

export async function apiFetch(path, options = {}) {
  const response = await fetch(path, {
    ...options,
    headers: { Accept: 'application/json', ...(options.headers ?? {}) },
  });

  // The Shopify library asks for a fresh grant by responding 403 with these
  // headers. OAuth consent cannot be framed, so this has to leave the iframe.
  if (
    response.status === 403 &&
    response.headers.get('X-Shopify-API-Request-Failure-Reauthorize') === '1'
  ) {
    const url = response.headers.get('X-Shopify-API-Request-Failure-Reauthorize-Url');

    if (url) {
      window.open(url.startsWith('/') ? `${window.location.origin}${url}` : url, '_top');
      throw new ApiError(403, 'reauthorization_required', 'Reauthorizing with Shopify.');
    }
  }

  const body = await response.json().catch(() => null);

  if (!response.ok) {
    const error = body?.error;

    throw new ApiError(
      response.status,
      error?.code ?? 'request_failed',
      error?.message ?? `The request failed (HTTP ${response.status}).`,
      error?.details ?? {},
    );
  }

  return body;
}

/** Drops empty values, so an untouched filter never reaches the server. */
export function queryString(params = {}) {
  const search = new URLSearchParams();

  for (const [key, value] of Object.entries(params)) {
    if (value === null || value === undefined || value === '') {
      continue;
    }

    search.set(key, String(value));
  }

  const query = search.toString();

  return query === '' ? '' : `?${query}`;
}
