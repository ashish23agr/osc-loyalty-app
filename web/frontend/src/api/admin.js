/**
 * The admin console API, one function per endpoint.
 *
 * Screens call these rather than assembling URLs, so a path or a parameter name
 * changes in one place. Only the endpoints the built screens use appear here;
 * the rest are added with the screen that needs them.
 */
import { apiFetch, queryString } from './client.js';

const BASE = '/api/admin';

/** Who is calling, and what the console may offer them. */
export function getMe() {
  return apiFetch(`${BASE}/me`);
}

/** The dashboard figures, and the job health the Loyalty screen reports. */
export function getOverview() {
  return apiFetch(`${BASE}/overview`);
}

/**
 * The customers list.
 *
 * `q` searches email, name, postcode, the club card number and the legacy
 * Dynamics card number at once; `field` narrows it to one of them (MD1).
 */
export function listMembers({ q, field, segment, channel, status, page, perPage } = {}) {
  return apiFetch(
    `${BASE}/members${queryString({
      q,
      field,
      segment,
      channel,
      status,
      page,
      per_page: perPage,
    })}`,
  );
}

/** The whole profile screen bar the paged ledger. */
export function getMember(id) {
  return apiFetch(`${BASE}/members/${id}`);
}

/** One page of a member movement history, newest first. */
export function getMemberLedger(id, { type, channel, from, to, page, perPage } = {}) {
  return apiFetch(`${BASE}/members/${id}/ledger${ledgerQuery({ type, channel, from, to, page, perPage })}`);
}

/**
 * The programme ledger behind the Loyalty screen, with its summary tiles in the
 * meta so the whole screen costs one request.
 */
export function listProgrammeLedger({ type, channel, from, to, q, page, perPage } = {}) {
  return apiFetch(`${BASE}/ledger${ledgerQuery({ type, channel, from, to, q, page, perPage })}`);
}

/**
 * Move points by hand.
 *
 * With `preview: true` the server writes nothing and returns the projection —
 * which is the only place the projected balance ever comes from. Working it out
 * on this side would mean a second implementation of the voucher arithmetic,
 * and the one that showed the member a number would be the one nobody tested.
 *
 * The idempotency key is generated once per modal, so a double click or a retry
 * after a dropped response returns the original adjustment rather than posting a
 * second one.
 */
export function adjustPoints(id, body, { idempotencyKey } = {}) {
  return apiFetch(`${BASE}/members/${id}/adjustments`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {}),
    },
    body: JSON.stringify(body),
  });
}

/** Who did what, when, before to after, and why. Manager floor. */
export function listAudit({
  action,
  actorStaffId,
  actorType,
  subjectType,
  subjectId,
  channel,
  q,
  from,
  to,
  page,
  perPage,
} = {}) {
  return apiFetch(
    `${BASE}/audit${queryString({
      action: Array.isArray(action) ? action.join(',') : action,
      actor_staff_id: actorStaffId,
      actor_type: actorType,
      subject_type: subjectType,
      subject_id: subjectId,
      channel,
      q,
      from,
      to,
      page,
      per_page: perPage,
    })}`,
  );
}

/** What is in force, and every version before it. */
export function getRules() {
  return apiFetch(`${BASE}/rules`);
}

/**
 * Save a new rule version.
 *
 * A full snapshot only — the server rejects a partial payload rather than
 * merging it, because merging would carry forward a value the person saving
 * never saw.
 */
export function saveRules({ rules, changeSummary } = {}) {
  return apiFetch(`${BASE}/rules`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ rules, change_summary: changeSummary }),
  });
}

function ledgerQuery({ type, channel, from, to, q, page, perPage }) {
  return queryString({
    type: Array.isArray(type) ? type.join(',') : type,
    channel: Array.isArray(channel) ? channel.join(',') : channel,
    from,
    to,
    q,
    page,
    per_page: perPage,
  });
}
