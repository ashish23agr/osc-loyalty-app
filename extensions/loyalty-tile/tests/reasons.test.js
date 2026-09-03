import {describe, expect, it} from 'vitest';

import {messageFor} from '../src/lib/reasons.js';

/**
 * The wording a failed request reaches the till as (V17).
 *
 * These call `messageFor` directly rather than asserting a string the test
 * itself supplied — the rule recorded on 3 Sep 2026: *if a test supplies what
 * production derives, something else must test the derivation.*
 */
describe('messageFor', () => {
  /**
   * The exact 403 body the backend returned on 3 Sep 2026, shaped as TillApi
   * hands it on.
   *
   * `EnsureStaffRole` produced this when the first real POS session token
   * arrived from a staff member with no row in `staff_roles`, and the tile
   * rendered it as "That search could not be run." This is the regression test
   * for that, built from the real response rather than an invented one.
   */
  const realNoRole = {
    ok: false,
    status: 403,
    error: 'no_role_assigned',
    message: 'This staff member has no Privilege Club role. An Administrator must assign one.',
    payload: {
      error: {
        code: 'no_role_assigned',
        message: 'This staff member has no Privilege Club role. An Administrator must assign one.',
      },
    },
  };

  it('says what is wrong and what to do for the real 403 that cost us a session', () => {
    const {title, detail} = messageFor(realNoRole);

    expect(title).toBe('Your till account is not set up yet');
    expect(detail).toContain('Privilege Club role');
    expect(detail).toContain('administrator');

    // The part that makes it usable at a till with a queue behind it.
    expect(detail).toContain('Continue the sale');

    // And it is emphatically not the string that hid this for a session.
    expect(detail).not.toContain('could not be run');
  });

  it.each([
    ['no_role_assigned'],
    ['role_floor_not_met'],
    ['invalid_session_token'],
    ['no_session'],
    ['no_app_url'],
    ['unreachable'],
  ])('gives %s till wording that tells the assistant what to do next', (code) => {
    const {title, detail} = messageFor({ok: false, error: code});

    expect(title).toBeTruthy();
    expect(detail).toBeTruthy();

    // C7: the sale must not stop, so every known failure says so. `unreachable`
    // says it in its own words because points still arrive later.
    expect(detail.toLowerCase()).toMatch(/continue the sale|added automatically/);
  });

  it('distinguishes a missing role from a role that is too low', () => {
    expect(messageFor({ok: false, error: 'no_role_assigned'}).title)
      .not.toBe(messageFor({ok: false, error: 'role_floor_not_met'}).title);
  });

  /**
   * The assertion that stops V17 recurring.
   *
   * A backend error nobody has mapped yet must still arrive as the server's own
   * sentence. Falling back to a generic string is the defect, not the fallback.
   */
  it('falls back to the server message for a code it has never seen', () => {
    const {detail} = messageFor({
      ok: false,
      status: 422,
      error: 'some_future_code_nobody_mapped',
      message: 'The quote expired before the sale completed.',
    });

    expect(detail).toBe('The quote expired before the sale completed.');
  });

  it('trims a server message rather than passing whitespace through', () => {
    expect(messageFor({ok: false, error: 'unmapped', message: '  Spaced out.  '}).detail)
      .toBe('Spaced out.');
  });

  it.each([
    ['an unknown code with no message', {ok: false, error: 'unmapped'}],
    ['an unknown code with an empty message', {ok: false, error: 'unmapped', message: ''}],
    ['an unknown code with a whitespace message', {ok: false, error: 'unmapped', message: '   '}],
    ['no error code at all', {ok: false}],
    ['undefined', undefined],
  ])('is still actionable given %s', (_label, response) => {
    const {title, detail} = messageFor(response);

    expect(title).toBeTruthy();
    expect(detail).toContain('Continue the sale');
  });
});
