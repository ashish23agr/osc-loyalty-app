import {describe, expect, it, vi} from 'vitest';

import {TillApi} from '../src/lib/api.js';
import {APP_URL, resolveAppUrl} from '../src/lib/appUrl.js';

/**
 * The base URL, and the refusal when there is not one.
 *
 * **Why this file exists at all.** `tests/tile.test.js` constructs `TillApi`
 * with an explicit `appUrl`, so the one line that decided the base URL was the
 * one line nothing exercised — and it was wrong for as long as the tile has
 * existed. Third instance of that pattern in this project after C14, and the
 * rule it produced is in DECISIONS.md: **if a test supplies what production
 * derives, something else must test the derivation.** This is that something
 * else.
 *
 * The request-shaping tests in `tile.test.js` keep passing `appUrl` in — that
 * is legitimate, because they are about headers, methods and payloads. They are
 * simply not evidence about where the request goes.
 */
describe('the base URL a till request is built on', () => {
  const noFetch = () => {
    throw new Error('fetchImpl must not be called when there is no base URL');
  };

  describe('TillApi.isUsableBase', () => {
    it('accepts an absolute https origin', () => {
      expect(TillApi.isUsableBase('https://app.example.com')).toBe(true);
    });

    it.each([
      ['the empty string, which is what the defect produced', ''],
      ['a bare path', '/api/admin'],
      ['http rather than https, which POS refuses outright', 'http://app.example.com'],
      ['a protocol-relative URL', '//app.example.com'],
      ['undefined', undefined],
      ['null', null],
      ['a host with a trailing path', 'https://app.example.com/api'],
    ])('rejects %s', (_label, value) => {
      expect(TillApi.isUsableBase(value)).toBe(false);
    });
  });

  /**
   * The test that would have caught the defect.
   *
   * No `appUrl` is supplied at all — the exact condition the tile was in on the
   * device — and the assertion is that nothing is sent. Before the guard this
   * issued a relative fetch that left no trace anywhere.
   */
  it('refuses to send anything when no appUrl was supplied', async () => {
    const fetchImpl = vi.fn(noFetch);

    const api = new TillApi({
      getSessionToken: async () => 'token',
      fetchImpl,
    });

    const result = await api.search('D-118422');

    expect(result).toEqual({ok: false, error: 'no_app_url', status: 0});
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it('refuses on a relative base without minting a session token', async () => {
    const fetchImpl = vi.fn(noFetch);
    const getSessionToken = vi.fn(async () => 'token');

    const api = new TillApi({getSessionToken, appUrl: '', fetchImpl});
    const result = await api.member(10);

    expect(result.error).toBe('no_app_url');
    expect(fetchImpl).not.toHaveBeenCalled();

    // No point asking POS for a token we could not have used.
    expect(getSessionToken).not.toHaveBeenCalled();
  });

  it('still refuses on the POST paths, not only the reads', async () => {
    const fetchImpl = vi.fn(noFetch);

    const api = new TillApi({
      getSessionToken: async () => 'token',
      appUrl: '/api/admin',
      fetchImpl,
    });

    const held = await api.hold({
      memberId: 10,
      eligibleSubtotalPence: 6000,
      basketTotalPence: 10000,
      requestedPence: 5000,
      locationId: 1,
      staffMemberId: 2,
    });

    expect(held.error).toBe('no_app_url');
    expect(fetchImpl).not.toHaveBeenCalled();
  });
});

describe('resolveAppUrl', () => {
  /**
   * The three host shapes, taken from what the device actually produced.
   *
   * `shopify.environment` does not exist in the POS surface: `StandardApi`
   * composes locale, toast, session, print, product search, device,
   * connectivity, storage and pin pad, and no `EnvironmentApi`. Its
   * `{[key: string]: any}` index signature is why reading it was never a type
   * error, and `?? ''` is why it was never a runtime one. Each of these must
   * yield the compiled constant, and none may yield ''.
   */
  it.each([
    ['no environment key at all', {}],
    ['environment explicitly undefined', {environment: undefined}],
    ['an environment object with no appUrl', {environment: {}}],
    ['an appUrl that is the empty string', {environment: {appUrl: ''}}],
    ['an appUrl that is a bare path', {environment: {appUrl: '/api/admin'}}],
    ['an appUrl on http rather than https', {environment: {appUrl: 'http://app.example.com'}}],
    ['no host global whatsoever', undefined],
  ])('falls back to the compiled constant when the host offers %s', (_label, hostGlobal) => {
    const resolved = resolveAppUrl(hostGlobal);

    expect(resolved).toBe(APP_URL);
    expect(resolved).not.toBe('');
    expect(TillApi.isUsableBase(resolved)).toBe(true);
  });

  it('prefers a usable absolute https URL if a future API version supplies one', () => {
    expect(resolveAppUrl({environment: {appUrl: 'https://supplied.example.com'}}))
      .toBe('https://supplied.example.com');
  });

  it('strips a trailing slash from a host-supplied URL', () => {
    expect(resolveAppUrl({environment: {appUrl: 'https://supplied.example.com/'}}))
      .toBe('https://supplied.example.com');
  });

  /**
   * The constant itself has to be usable, whatever it currently holds.
   *
   * In development this is the tunnel that `web/serve.mjs` wrote; at release it
   * is the production URL. Either way an unusable value here would put every
   * till into the no_app_url refusal, so it is worth one assertion.
   */
  it('ships a base URL TillApi will actually send to', () => {
    expect(TillApi.isUsableBase(APP_URL)).toBe(true);
  });

  it('never returns a value that TillApi would refuse', async () => {
    const fetchImpl = vi.fn(async () => ({
      ok: true,
      status: 200,
      json: async () => ({data: []}),
    }));

    const api = new TillApi({
      getSessionToken: async () => 'token',
      appUrl: resolveAppUrl({}),
      fetchImpl,
    });

    const result = await api.search('D-118422');

    expect(result.ok).toBe(true);
    expect(fetchImpl).toHaveBeenCalledTimes(1);
    expect(fetchImpl.mock.calls[0][0]).toBe(`${APP_URL}/api/admin/members?q=D-118422&per_page=10`);
  });
});
