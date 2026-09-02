/**
 * Wording for the coded values the API sends.
 *
 * The server sends codes because a code is a contract; the wording that goes in
 * front of OSC staff belongs on this side, where changing it costs nothing.
 * Taken from the agreed UI (v1.1 FINAL) where that file names a thing.
 */
import { NOT_HELD } from './format.js';

export const SEGMENT = {
  active: { label: 'Active', tone: 'success' },
  lapsed: { label: 'Lapsed', tone: 'warning' },
  unknown: { label: 'Unknown', tone: 'neutral' },
};

export const CHANNEL = {
  online: 'Online',
  pos: 'In-store',
  admin: 'Console',
  system: 'Automatic',
  migration: 'Migrated',
};

/** How a member came to be enrolled, as the profile subtitle says it. */
export const ENROLMENT = {
  online: 'enrolled online',
  pos: 'enrolled in store',
  admin: 'enrolled in the console',
  migration: 'migrated from Dynamics',
};

export const ENTRY_TYPE = {
  earn: 'Earn',
  maturity: 'Maturity',
  expiry: 'Expiry',
  redemption: 'Redemption',
  redemption_restore: 'Redemption restored',
  earn_reversal: 'Reversal',
  adjustment: 'Adjustment',
  opening_balance: 'Opening balance',
};

/** Where the points stand now, not where they stood when the entry was posted. */
export const ENTRY_STATUS = {
  pending: { label: 'Pending', tone: 'warning' },
  available: { label: 'Available', tone: 'success' },
  expired: { label: 'Expired', tone: 'neutral' },
  applied: { label: 'Applied', tone: 'success' },
  reversed: { label: 'Reversed', tone: 'neutral' },
};

export const REWARD_TYPE = {
  birthday: 'Birthday reward',
  goodwill: 'Goodwill',
};

/**
 * The adjustment reason categories, in the wording of the agreed UI.
 *
 * The keys are the server's closed list — `AdjustmentService::REASON_CATEGORIES`
 * — so a category added there and not here simply will not be offered, rather
 * than being sent as a value the validator rejects.
 */
export const REASON_CATEGORIES = {
  goodwill: 'Goodwill gesture',
  correction_missed_earning: 'Correction — missed earning',
  correction_duplicate_earning: 'Correction — duplicate earning',
  migration_correction: 'Migration correction',
  other: 'Other (describe below)',
};

/** How the audit log names each action, per the agreed UI's Action filter. */
export const AUDIT_ACTION = {
  'points.adjusted': 'Points adjustment',
  'balance.rebuilt': 'Balance rebuilt',
  'rules.saved': 'Rule change',
  'reward.issued': 'Voucher issued',
  'reward.cancelled': 'Voucher cancelled',
  'reward.reissued': 'Voucher reissued',
  'redemption.confirmed': 'Redemption',
  'redemption.voided': 'Redemption voided',
  'redemption.reversed': 'Redemption reversed',
  'account.enrolled': 'Enrolment',
  'account.updated': 'Profile correction',
  'account.merged': 'Accounts merged',
  'staff.role_assigned': 'Role assigned',
  'staff.bootstrapped': 'First Administrator',
  'migration.committed': 'Migration',
  'migration.exception_resolved': 'Migration exception resolved',
};

/** The role a person held, as the agreed UI writes it. */
export const ROLE = {
  viewer: 'Viewer',
  agent: 'Agent',
  manager: 'Loyalty manager',
  administrator: 'Administrator',
};

export const ACTOR_TYPE = {
  staff: 'Staff',
  system: 'System',
  pos: 'Till',
  migration: 'Migration',
};

export const REWARD_STATE = {
  issued: { label: 'Active', tone: 'success' },
  redeemed: { label: 'Redeemed', tone: 'neutral' },
  expired: { label: 'Expired', tone: 'neutral' },
  cancelled: { label: 'Cancelled', tone: 'critical' },
  superseded: { label: 'Reissued', tone: 'neutral' },
};

/**
 * Marketing consent as three states, not two.
 *
 * Null is not "no". A migrated member who was never asked is a different person
 * from one who declined, and MD4 turns on exactly that distinction.
 */
export function consentLabel(consent) {
  if (consent === true) {
    return { label: 'Opted in', tone: 'success' };
  }

  if (consent === false) {
    return { label: 'Not subscribed', tone: 'neutral' };
  }

  return { label: 'Not asked', tone: 'neutral' };
}

export function lookup(map, key, fallback = NOT_HELD) {
  return map[key] ?? fallback;
}
