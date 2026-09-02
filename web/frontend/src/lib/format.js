/**
 * Presentation of the values the API sends as integers.
 *
 * Money crosses the wire as pence with a `_pence` suffix and points as whole
 * numbers, so every pound sign and thousands separator in the console is
 * applied here and nowhere else.
 */

/** What a screen shows where this programme does not hold the value. */
export const NOT_HELD = '—';

const POUNDS = new Intl.NumberFormat('en-GB', {
  style: 'currency',
  currency: 'GBP',
  minimumFractionDigits: 2,
});

const WHOLE_POUNDS = new Intl.NumberFormat('en-GB', {
  style: 'currency',
  currency: 'GBP',
  minimumFractionDigits: 0,
  maximumFractionDigits: 0,
});

const NUMBER = new Intl.NumberFormat('en-GB');

const MONTHS = [
  'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
];

/**
 * Pence as the agreed UI writes them: whole pounds lose the pence, so a
 * voucher reads "£15" and a basket reads "£72.40".
 */
export function formatPence(pence) {
  if (pence === null || pence === undefined) {
    return NOT_HELD;
  }

  const amount = Number(pence) / 100;

  return Number(pence) % 100 === 0 ? WHOLE_POUNDS.format(amount) : POUNDS.format(amount);
}

export function formatPoints(points) {
  if (points === null || points === undefined) {
    return NOT_HELD;
  }

  return NUMBER.format(Number(points));
}

/**
 * A movement, signed, with a true minus rather than a hyphen.
 *
 * Zero reads as an em dash: a maturity moves points between buckets without
 * creating any, and "+0" in a Points column says nothing useful.
 */
export function formatDelta(points) {
  const value = Number(points ?? 0);

  if (value === 0) {
    return NOT_HELD;
  }

  return value > 0 ? `+${NUMBER.format(value)}` : `−${NUMBER.format(Math.abs(value))}`;
}

/** "14 Mar 2019" */
export function formatDate(iso) {
  const date = toDate(iso);

  if (date === null) {
    return NOT_HELD;
  }

  return `${date.getDate()} ${MONTHS[date.getMonth()]} ${date.getFullYear()}`;
}

/** "13 Aug 09:31" — the year is noise in a movement history. */
export function formatDateTime(iso) {
  const date = toDate(iso);

  if (date === null) {
    return NOT_HELD;
  }

  const hours = String(date.getHours()).padStart(2, '0');
  const minutes = String(date.getMinutes()).padStart(2, '0');

  return `${date.getDate()} ${MONTHS[date.getMonth()]} ${hours}:${minutes}`;
}

/** "13 Aug 2026, 09:42" — for a figure that is only true at a moment. */
export function formatDateTimeLong(iso) {
  const date = toDate(iso);

  if (date === null) {
    return NOT_HELD;
  }

  const hours = String(date.getHours()).padStart(2, '0');
  const minutes = String(date.getMinutes()).padStart(2, '0');

  return `${formatDate(iso)}, ${hours}:${minutes}`;
}

/**
 * A change against the previous period, signed.
 *
 * Null rather than zero when there is nothing to compare against, because a
 * first month with no history is not a month with no growth.
 */
export function formatChange(percent) {
  if (percent === null || percent === undefined) {
    return NOT_HELD;
  }

  return percent > 0 ? `+${percent}%` : `${percent}%`;
}

/** "12 Sep" or "12 Sep 1958" — MD2: a birthday need not carry a year. */
export function formatBirthday({ dob_day: day, dob_month: month, date_of_birth: full }) {
  if (!day || !month) {
    return NOT_HELD;
  }

  const label = `${day} ${MONTHS[month - 1]}`;
  const year = full ? toDate(full)?.getFullYear() : null;

  return year ? `${label} ${year}` : label;
}

export function initials(firstName, lastName) {
  const letters = [firstName, lastName]
    .map((part) => (part ?? '').trim().charAt(0).toUpperCase())
    .filter(Boolean)
    .join('');

  return letters === '' ? '?' : letters;
}

function toDate(iso) {
  if (!iso) {
    return null;
  }

  const date = new Date(iso);

  return Number.isNaN(date.getTime()) ? null : date;
}
