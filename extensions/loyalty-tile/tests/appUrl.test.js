import {describe, expect, it, vi} from 'vitest';

import {TillApi} from '../src/lib/api.js';

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
