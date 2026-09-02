import { render, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

import { SessionGate, SessionProvider } from '../src/app/SessionProvider.jsx';

/**
 * Polaris components take their heading and their field label as attributes,
 * and render them from inside the component. The App Bridge script that
 * registers those components does not load in jsdom, so the attribute is
 * present but its text never reaches the DOM and getByText cannot see it.
 *
 * These read the attribute instead. That is a real limitation of testing
 * unregistered custom elements, not a workaround to be replaced later: what can
 * be asserted here is the tree and the data in it, and how any of it looks is
 * out of reach either way.
 */
export function queryByAttribute(attribute, value) {
  return (
    [...document.querySelectorAll(`[${attribute}]`)].find(
      (element) => element.getAttribute(attribute) === value,
    ) ?? null
  );
}

export function queryHeading(text) {
  return queryByAttribute('heading', text);
}

export function findHeading(text) {
  return waitFor(() => {
    const element = queryHeading(text);

    if (element === null) {
      throw new Error(`No element carries the heading "${text}".`);
    }

    return element;
  });
}

/** A form control, found by the label attribute it was given. */
export function queryField(label) {
  return queryByAttribute('label', label);
}

/**
 * A session as GET /api/admin/me returns it, for a given role.
 *
 * The permission floors are the server's, restated here only so a test can say
 * "as a viewer" without hand-writing twelve booleans. If the two ever disagree
 * the backend is right — AdminApiRoutingTest is what asserts the real floors.
 */
const FLOORS = {
  view_overview: 'viewer',
  view_members: 'viewer',
  enrol_members: 'agent',
  edit_members: 'agent',
  view_ledger: 'viewer',
  adjust_points: 'agent',
  rebuild_caches: 'manager',
  view_rules: 'viewer',
  edit_rules: 'administrator',
  view_audit: 'manager',
  view_staff: 'administrator',
  manage_staff: 'administrator',
};

export const ROLES = ['viewer', 'agent', 'manager', 'administrator'];

export function sessionFor(role, overrides = {}) {
  const rank = ROLES.indexOf(role);

  const permissions = Object.fromEntries(
    Object.entries(FLOORS).map(([permission, floor]) => [
      permission,
      rank >= ROLES.indexOf(floor),
    ]),
  );

  return {
    shop_domain: 'loyalty-system.myshopify.com',
    staff: {
      shopify_staff_id: 100000001,
      staff_name: 'N. Mehra',
      staff_email: null,
      role,
      rank,
      adjustment_limit_points: null,
      effective_adjustment_limit_points: rank >= 2 ? null : 500,
    },
    permissions,
    roles: ROLES,
    rules_version: 1,
    currency: 'GBP',
    reporting_timezone: 'Europe/London',
    ...overrides,
  };
}

/**
 * Render a screen the way the app renders it: inside a router, behind the
 * session gate, with a role in hand.
 */
export function renderWithSession(
  ui,
  { role = 'administrator', session, route = '/', loadSession } = {},
) {
  const resolved = session ?? sessionFor(role);

  return render(
    <MemoryRouter initialEntries={[route]}>
      <SessionProvider loadSession={loadSession ?? (() => Promise.resolve(resolved))}>
        <SessionGate>{ui}</SessionGate>
      </SessionProvider>
    </MemoryRouter>,
  );
}

/** A member as GET /api/admin/members/{id} returns them. */
export function memberFixture(overrides = {}) {
  return {
    id: 1,
    card_number: '0000-0001',
    legacy_card_number: 'D-118422',
    shopify_customer_id: 7412200034561,
    email: 'm.whitfield@example.co.uk',
    has_no_email: false,
    first_name: 'Margaret',
    last_name: 'Whitfield',
    display_name: 'Margaret Whitfield',
    postcode: 'SP4 6AB',
    segment: 'active',
    status: 'active',
    enrolment_channel: 'migration',
    enrolled_at: '2019-03-14T09:00:00+00:00',
    points_available: 340,
    points_pending: 72,
    voucher_balance_pence: 1500,
    shop_domain: 'loyalty-system.myshopify.com',
    date_of_birth: null,
    dob_month: 9,
    dob_day: 12,
    has_birthday: true,
    gender: 'female',
    email_marketing_consent: true,
    consent_updated_at: null,
    segment_calculated_at: null,
    last_qualifying_spend_at: null,
    is_merged: false,
    merged_into_account_id: null,
    balances: {
      points_pending: 72,
      points_available: 340,
      points_lifetime: 340,
      voucher_balance_pence: 1500,
      voucher_increments: 3,
      points_committed_to_voucher: 300,
      remainder_points: 40,
      points_to_next_increment: 60,
      caches_rebuilt_at: '2026-08-27T12:05:03+00:00',
    },
    lifetime_spend_pence: 418200,
    last_purchase_at: '2026-08-09T14:02:00+00:00',
    pending_clears_at: '2026-09-12T09:31:00+01:00',
    expiring_soon: { points: 0, next_expires_at: null, window_days: 30 },
    rewards: [],
    rules: {
      version: 1,
      voucher_threshold_points: 100,
      voucher_value_pence: 500,
      pending_period_days: 30,
      expiry_warning_days: 30,
      max_redemption_per_order_pence: 5000,
    },
    ...overrides,
  };
}

/** One line of the customers list. */
export function memberRowFixture(overrides = {}) {
  const member = memberFixture(overrides);

  return {
    id: member.id,
    card_number: member.card_number,
    legacy_card_number: member.legacy_card_number,
    shopify_customer_id: member.shopify_customer_id,
    email: member.email,
    has_no_email: member.has_no_email,
    first_name: member.first_name,
    last_name: member.last_name,
    display_name: member.display_name,
    postcode: member.postcode,
    segment: member.segment,
    status: member.status,
    enrolment_channel: member.enrolment_channel,
    enrolled_at: member.enrolled_at,
    points_available: member.points_available,
    points_pending: member.points_pending,
    voucher_balance_pence: member.voucher_balance_pence,
  };
}

export function ledgerEntryFixture(overrides = {}) {
  return {
    id: 3,
    occurred_at: '2026-08-13T09:31:00+01:00',
    created_at: '2026-08-13T09:31:00+01:00',
    entry_type: 'earn',
    channel: 'pos',
    reference: '#POS-8841',
    pending_delta: 72,
    available_delta: 0,
    points: 72,
    bucket: 'pending',
    status: 'pending',
    reason: null,
    order_name: '#POS-8841',
    shopify_order_id: 8841,
    shopify_refund_id: null,
    shopify_location_id: 6612440091,
    staff_reference: null,
    redemption_id: null,
    reward_id: null,
    parent_entry_id: null,
    qualifying_value_pence: 7240,
    matures_at: '2026-09-12T09:31:00+01:00',
    expires_at: '2027-03-13T09:31:00+00:00',
    rules_version_id: 1,
    available_after: 340,
    pending_after: 72,
    ...overrides,
  };
}

export function collection(data, meta = {}) {
  return {
    data,
    meta: { page: 1, per_page: 25, total: data.length, last_page: 1, ...meta },
  };
}

/** An audit entry as GET /api/admin/audit returns it. */
export function auditEntryFixture(overrides = {}) {
  return {
    id: 11,
    created_at: '2026-08-13T09:44:00+01:00',
    actor: { type: 'staff', staff_id: 100000001, name: 'N. Mehra', role: 'manager' },
    action: 'points.adjusted',
    subject_type: 'LoyaltyAccount',
    subject_id: 1,
    reason: 'Goodwill gesture — damaged acer (#4471)',
    before_state: { points_available: 340 },
    after_state: { points_available: 390, reason_category: 'goodwill' },
    changes: [
      { field: 'points_available', from: 340, to: 390 },
      { field: 'reason_category', from: null, to: 'goodwill' },
    ],
    channel: 'admin',
    ip_address: '203.0.113.4',
    request_id: '3f1c8f6e-0000-4000-8000-000000000000',
    ...overrides,
  };
}

/** One row of the programme ledger, which names its member. */
export function programmeEntryFixture(overrides = {}) {
  const { member, ...rest } = overrides;

  return {
    ...ledgerEntryFixture(rest),
    member: {
      id: 1,
      display_name: 'Margaret Whitfield',
      card_number: '0000-0001',
      legacy_card_number: 'D-118422',
      email: 'm.whitfield@example.co.uk',
      ...member,
    },
  };
}

export function summaryFixture(overrides = {}) {
  return {
    points_in_circulation: 2410000,
    points_pending: 42180,
    points_earned_today: 6140,
    // The spend behind today's earning, aggregated now that Sprint 2's earning
    // side actually posts it.
    qualifying_spend_today_pence: 614000,
    points_reversed_today: -214,
    reversals_today: {
      refunds: { entries: 9, points: -186 },
      cancellations: { entries: 2, points: -28 },
    },
    expiring_soon: { points: 18240, lots: 412, next_expires_at: null, window_days: 30 },
    voucher_value_outstanding_pence: 12050000,
    ...overrides,
  };
}

export function overviewFixture(overrides = {}) {
  return {
    generated_at: '2026-08-13T09:42:00+01:00',
    reporting_timezone: 'Europe/London',
    currency: 'GBP',
    members: { total: 41286, active: 41286, segment_active: 28904, segment_lapsed: 12382, without_email: 1471 },
    enrolments: { last_30_days: 612, previous_30_days: 519, change_percent: 18, by_channel: {} },
    points: summaryFixture(),
    redemptions: { last_30_days: 3224, last_30_days_pence: 1612000 },
    jobs: {
      overdue_maturities: { entries: 0, points: 0 },
      overdue_expiries: { lots: 0, points: 0 },
      oldest_pending_clears_at: '2026-08-14T06:00:00+01:00',
      oldest_cache_rebuilt_at: '2026-08-13T03:00:00+01:00',
      // From the audit log (C12), and from the schedule itself.
      last_runs: {
        'loyalty:mature-points': { at: '2026-08-13T09:00:00+01:00', counts: {} },
        'loyalty:expire-points': { at: '2026-08-13T02:10:00+01:00', counts: {} },
      },
      next_expiry_run_at: '2026-08-14T02:10:00+01:00',
      next_maturity_run_at: '2026-08-13T10:00:00+01:00',
    },
    ...overrides,
  };
}

/** The launch rule payload, as RuleSet::defaults() produces it. */
export function rulePayloadFixture(overrides = {}) {
  return {
    earn_points_per_pound: 1,
    pending_period_days: 30,
    points_expiry_days: 182,
    expiry_clock_starts: 'availability',
    voucher_threshold_points: 100,
    voucher_value_pence: 500,
    birthday_reward_pence: 1000,
    birthday_reward_expiry_days: 90,
    max_redemption_per_order_pence: 5000,
    min_basket_pence: 0,
    earn_base: 'post_discount_ex_tax_ex_shipping',
    qualification: {
      mode: 'all',
      include_collections: [],
      include_tags: [],
      exclude_collections: [],
      exclude_tags: [],
    },
    online_redemption_enabled: true,
    pos_redemption_enabled: true,
    expiry_warning_days: 30,
    migrated_balance_grace_days: 182,
    agent_adjustment_limit_points: 500,
    currency: 'GBP',
    reporting_timezone: 'Europe/London',
    ...overrides,
  };
}

export function rulesFixture(overrides = {}) {
  return {
    current: {
      version_id: 1,
      version: 1,
      effective_from: '2016-08-27T00:00:00+01:00',
      change_summary: 'Launch values from Blueprint section 06.',
      created_by_staff_id: null,
      created_by_name: null,
      created_at: '2026-08-26T10:00:00+01:00',
      payload: rulePayloadFixture(),
    },
    versions: [],
    editable_by: 'administrator',
    ...overrides,
  };
}

/** The adjustment preview, as the endpoint returns it with preview: true. */
export function adjustmentPreviewFixture(overrides = {}) {
  return {
    preview: true,
    member_id: 1,
    projection: {
      bucket: 'available',
      points_delta: 50,
      points_pending_before: 72,
      points_pending_after: 72,
      points_available_before: 340,
      points_available_after: 390,
      voucher_balance_pence_before: 1500,
      voucher_balance_pence_after: 1500,
      voucher_increments_after: 3,
      remainder_points_after: 90,
      points_to_next_increment_after: 10,
    },
    limit: { points: 500, requested_points: 50, exceeded: false },
    reason_categories: {
      goodwill: 'Goodwill gesture',
      correction_missed_earning: 'Correction — missed earning',
      correction_duplicate_earning: 'Correction — duplicate earning',
      migration_correction: 'Migration correction',
      other: 'Other',
    },
    minimum_note_length: 10,
    ...overrides,
  };
}

/**
 * The current value of a form control, property first.
 *
 * React 19 sets a prop on a custom element as an **attribute** while the element
 * has no matching property, and switches to the **property** as soon as one
 * exists. A test that has typed into a field has created that property, so the
 * attribute stops tracking. In a browser the registered Polaris component
 * defines `value` from the start and React always uses the property — which is
 * what the component reads — so this only bites in jsdom.
 */
export function fieldValue(label) {
  const element = queryField(label);

  return element.value === undefined ? element.getAttribute('value') : String(element.value);
}
