import {describe, expect, test} from 'vitest';

import {TillApi} from '../src/lib/api.js';
import {enrolmentProblem, toPayload, validate} from '../src/lib/enrolment.js';
import {canScan, classify, fromScan, hintFor} from '../src/lib/lookup.js';
import {formatPence, stepDown, steps, stepUp} from '../src/lib/money.js';
import {isBasketFixable, refusalFor} from '../src/lib/reasons.js';
import {isConnected, tileState} from '../src/lib/tileState.js';

/**
 * The POS tile's logic, tested without rendering POS.
 *
 * The components are deliberately thin over these modules, because a POS surface
 * cannot be rendered in a test environment and the parts that have to be RIGHT —
 * what the tile says when the till is offline, what the step control may offer,
 * what a refusal reads as, whether a duplicate enrolment is treated as a failure
 * — are all decisions rather than markup.
 */

describe('the offline rule (C7)', () => {
  /**
   * The state the brief singles out. Two things have to be true at once: the
   * tile must refuse, and the assistant must know the SALE is unaffected.
   */
  test('a disconnected till says loyalty is unavailable and the sale continues', () => {
    const state = tileState({connected: false, session: {userId: 1}});

    expect(state.status).toBe('offline');
    expect(state.enabled).toBe(false);
    expect(state.heading).toMatch(/unavailable/i);

    // The half that stops an assistant abandoning the sale.
    expect(state.subheading).toMatch(/continue the sale/i);
    expect(state.subheading).toMatch(/back online/i);
  });

  test('a connected till with a session is ready', () => {
    const state = tileState({connected: true, session: {userId: 1}});

    expect(state.status).toBe('ready');
    expect(state.enabled).toBe(true);
  });

  test('an unreachable app degrades the same way, not into an error', () => {
    const state = tileState({connected: true, session: {userId: 1}, error: 'unreachable'});

    expect(state.enabled).toBe(false);
    expect(state.subheading).toMatch(/continue the sale/i);
  });

  test('POS reports connectivity as a string, not a boolean', () => {
    expect(isConnected({internetConnected: 'Connected'})).toBe(true);
    expect(isConnected({internetConnected: 'Disconnected'})).toBe(false);
    // Unknown is treated as connected: the server refuses anything it should.
    expect(isConnected(undefined)).toBe(true);
  });
});

describe('the redeem control', () => {
  test('offers whole increments up to what the server agreed', () => {
    expect(steps(4500, 500)).toEqual([500, 1000, 1500, 2000, 2500, 3000, 3500, 4000, 4500]);
  });

  test('offers nothing when the maximum is under one increment', () => {
    expect(steps(400, 500)).toEqual([]);
  });

  test('never steps above the agreed maximum', () => {
    expect(stepUp(4500, 4500, 500)).toBe(4500);
    expect(stepUp(4000, 4500, 500)).toBe(4500);
  });

  test('never steps below one increment', () => {
    expect(stepDown(500, 500)).toBe(500);
    expect(stepDown(1500, 500)).toBe(1000);
  });

  /**
   * The agreed UI's rejection example, as the control would render it: the
   * member holds £50, the basket qualifies for £45, and the control tops out at
   * £45 rather than offering the £50 and failing.
   */
  test('the agreed UI rejection example tops out at what qualifies', () => {
    const offered = steps(4500, 500);

    expect(offered.at(-1)).toBe(4500);
    expect(offered).not.toContain(5000);
  });

  test('money reads as the agreed UI writes it', () => {
    expect(formatPence(4500)).toBe('£45');
    expect(formatPence(750)).toBe('£7.50');
    expect(formatPence(null)).toBe('—');
  });
});

describe('refusal reasons on screen', () => {
  test('every ladder reason has wording a person can act on', () => {
    for (const reason of [
      'no_voucher_balance',
      'no_eligible_items',
      'minimum_basket_not_met',
      'below_voucher_increment',
    ]) {
      const shown = refusalFor(reason);

      expect(shown.title, reason).toBeTruthy();
      expect(shown.detail, reason).toBeTruthy();
      expect(shown.detail.length, reason).toBeGreaterThan(20);
    }
  });

  /** The reason added this sprint, which the tile has to explain. */
  test('below one increment explains itself rather than showing a dead button', () => {
    const shown = refusalFor('below_voucher_increment');

    expect(shown.detail).toMatch(/whole increments/i);
    expect(isBasketFixable('below_voucher_increment')).toBe(true);
  });

  test('an unknown reason still says something', () => {
    expect(refusalFor('something_new').title).toBeTruthy();
  });

  test('a member with no balance is not the assistant\'s to fix', () => {
    expect(isBasketFixable('no_voucher_balance')).toBe(false);
  });
});

describe('the lookup box', () => {
  test('recognises every identifier the agreed UI lists', () => {
    expect(classify('m.whitfield@example.co.uk')).toBe('email');
    expect(classify('D-118422')).toBe('legacy_card');
    expect(classify('0041-2287')).toBe('club_card');
    expect(classify('0041 2287')).toBe('club_card');
    expect(classify('SP4 6AB')).toBe('postcode');
    expect(classify('Margaret Whitfield')).toBe('name');
    expect(classify('   ')).toBe('empty');
  });

  test('tells the assistant what it is about to search for', () => {
    expect(hintFor('D-118422')).toMatch(/dynamics/i);
    expect(hintFor('')).toMatch(/email, name, postcode or card/i);
  });

  /** V7: a scan is typed input that arrived faster. */
  test('a scan is trimmed and otherwise untouched', () => {
    expect(fromScan('  D-118422 ')).toBe('D-118422');
    expect(fromScan(undefined)).toBe('');
  });

  test('the scan button appears only where a scanner exists', () => {
    expect(canScan(['camera'])).toBe(true);
    expect(canScan([])).toBe(false);
    expect(canScan(undefined)).toBe(false);
  });
});

describe('enrol at the till', () => {
  /** MD1: an email address is optional, and demanding one would be wrong. */
  test('a member with no email may be enrolled on postcode and surname', () => {
    const check = validate({lastName: 'Whitfield', postcode: 'SP4 6AB'});

    expect(check.valid).toBe(true);
  });

  test('a surname is required, because it is half the no-email identity', () => {
    expect(validate({postcode: 'SP4 6AB'}).errors.lastName).toBeTruthy();
  });

  test('neither an email nor a postcode leaves nobody findable', () => {
    expect(validate({lastName: 'Whitfield'}).errors.postcode).toBeTruthy();
  });

  test('a malformed email is caught before a round trip', () => {
    expect(validate({lastName: 'Whitfield', email: 'not-an-email'}).errors.email).toBeTruthy();
  });

  /** MD2: a day and month with no year is a complete birthday here. */
  test('a birthday with no year is sent', () => {
    const payload = toPayload({lastName: 'Whitfield', postcode: 'SP4 6AB', dobDay: '12', dobMonth: '9'});

    expect(payload.dob_day).toBe(12);
    expect(payload.dob_month).toBe(9);
    expect(payload.date_of_birth).toBeUndefined();
  });

  test('blank fields are omitted rather than sent empty', () => {
    const payload = toPayload({lastName: 'Whitfield', postcode: 'SP4 6AB'});

    expect(payload).toEqual({last_name: 'Whitfield', postcode: 'SP4 6AB'});
  });

  /**
   * D10: a duplicate is not a failure. It means this person is already a member,
   * and the useful response is to open their account.
   */
  test('a duplicate email points at the existing member', () => {
    const problem = enrolmentProblem({
      error: 'duplicate_email',
      payload: {error: {details: {existing_account_id: 41}}},
    });

    expect(problem.kind).toBe('duplicate');
    expect(problem.existingAccountId).toBe(41);
    expect(problem.detail).toMatch(/already enrolled/i);
  });

  test('an unreachable app tells the assistant to carry on with the sale', () => {
    const problem = enrolmentProblem({error: 'unreachable'});

    expect(problem.kind).toBe('offline');
    expect(problem.detail).toMatch(/continue the sale/i);
  });
});

describe('the till API client', () => {
  const tokenised = (fetchImpl) => new TillApi({
    getSessionToken: async () => 'token-123',
    appUrl: 'https://app.example.com/',
    fetchImpl,
  });

  test('sends the session token as a bearer header', async () => {
    let seen = null;

    const api = tokenised(async (url, options) => {
      seen = {url, options};

      return {ok: true, status: 200, json: async () => ({data: []})};
    });

    await api.search('whitfield');

    expect(seen.url).toBe('https://app.example.com/api/admin/members?q=whitfield&per_page=10');
    expect(seen.options.headers.Authorization).toBe('Bearer token-123');
  });

  test('a staff member with no app permission is a state, not a crash', async () => {
    const api = new TillApi({
      getSessionToken: async () => undefined,
      appUrl: '',
      fetchImpl: async () => {
        throw new Error('should not be called');
      },
    });

    const result = await api.quote({memberId: 1, eligibleSubtotalPence: 0, basketTotalPence: 0});

    expect(result.ok).toBe(false);
    expect(result.error).toBe('no_session');
  });

  test('a network failure becomes unreachable rather than an exception', async () => {
    const api = tokenised(async () => {
      throw new TypeError('Failed to fetch');
    });

    const result = await api.search('whitfield');

    expect(result.ok).toBe(false);
    expect(result.error).toBe('unreachable');
  });

  test('a refusal carries the server error code through', async () => {
    const api = tokenised(async () => ({
      ok: false,
      status: 409,
      json: async () => ({error: {code: 'duplicate_email', message: 'Already a member'}}),
    }));

    const result = await api.enrol({last_name: 'Whitfield'});

    expect(result.ok).toBe(false);
    expect(result.error).toBe('duplicate_email');
  });

  test('a hold sends the pinned staff member and the location', async () => {
    let body = null;

    const api = tokenised(async (url, options) => {
      body = JSON.parse(options.body);

      return {ok: true, status: 201, json: async () => ({redemption: {}})};
    });

    await api.hold({
      memberId: 41,
      eligibleSubtotalPence: 6000,
      basketTotalPence: 10000,
      requestedPence: 1500,
      locationId: 771,
      staffMemberId: 4242,
    });

    expect(body.channel).toBe('pos');
    expect(body.staff_member_id).toBe(4242);
    expect(body.shopify_location_id).toBe(771);
    expect(body.requested_pence).toBe(1500);
  });
});
