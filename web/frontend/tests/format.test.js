import { describe, expect, it } from 'vitest';

import {
  formatBirthday,
  formatDate,
  formatDelta,
  formatPence,
  formatPoints,
  initials,
  NOT_HELD,
} from '../src/lib/format.js';

describe('money', () => {
  it('drops the pence on a whole pound, as the agreed UI does', () => {
    expect(formatPence(1500)).toBe('£15');
    expect(formatPence(418200)).toBe('£4,182');
  });

  it('keeps the pence when there are any', () => {
    expect(formatPence(7240)).toBe('£72.40');
  });

  it('says nothing rather than zero when the value is not held', () => {
    expect(formatPence(null)).toBe(NOT_HELD);
    expect(formatPence(0)).toBe('£0');
  });
});

describe('points', () => {
  it('separates thousands', () => {
    expect(formatPoints(1120)).toBe('1,120');
  });

  it('signs a movement with a true minus, and shows nothing for a transfer', () => {
    expect(formatDelta(72)).toBe('+72');
    expect(formatDelta(-200)).toBe('−200');
    // A maturity moves points between buckets without creating any.
    expect(formatDelta(0)).toBe(NOT_HELD);
  });
});

describe('dates', () => {
  it('writes a date the way the agreed UI writes it', () => {
    expect(formatDate('2019-03-14T09:00:00Z')).toBe('14 Mar 2019');
  });

  it('falls back rather than printing Invalid Date', () => {
    expect(formatDate(null)).toBe(NOT_HELD);
    expect(formatDate('not a date')).toBe(NOT_HELD);
  });
});

describe('birthdays (MD2)', () => {
  it('shows a day and month with no year', () => {
    expect(formatBirthday({ dob_day: 12, dob_month: 9, date_of_birth: null })).toBe('12 Sep');
  });

  it('adds the year when a full date of birth is held', () => {
    expect(
      formatBirthday({ dob_day: 12, dob_month: 9, date_of_birth: '1958-09-12' }),
    ).toBe('12 Sep 1958');
  });

  it('says nothing when no birthday is held', () => {
    expect(formatBirthday({ dob_day: null, dob_month: null })).toBe(NOT_HELD);
  });
});

describe('initials', () => {
  it('takes one letter from each name', () => {
    expect(initials('Margaret', 'Whitfield')).toBe('MW');
  });

  it('copes with a member who has no name held', () => {
    expect(initials(null, null)).toBe('?');
    expect(initials('Eileen', null)).toBe('E');
  });
});
