import { screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { getOverview } from '../src/api/admin.js';
import { ApiError } from '../src/api/client.js';
import DashboardScreen from '../src/screens/DashboardScreen.jsx';
import {
  findHeading,
  overviewFixture,
  programmeEntryFixture,
  queryField,
  renderWithSession,
} from './support.jsx';

vi.mock('../src/api/admin.js', async () => (await import('./apiMock.js')).adminApiMock());

/**
 * A1 — Dashboard.
 *
 * Six cards and the activity strip, all from one request. The two figures this
 * system does not hold — the redemption rate, and any count of issued vouchers —
 * are the ones worth asserting hardest: an invented number here is one a
 * director would quote in a meeting.
 */
function renderDashboard({ role = 'viewer' } = {}) {
  return renderWithSession(<DashboardScreen />, { role, route: '/' });
}

/** The text of one summary card, found by its label. */
function card(label) {
  return screen.getByText(label).closest('s-box').textContent;
}

beforeEach(() => {
  getOverview.mockResolvedValue(
    overviewFixture({
      points: {
        ...overviewFixture().points,
        points_issued_last_30_days: 184520,
      },
      enrolments: {
        last_30_days: 612,
        previous_30_days: 519,
        change_percent: 18,
        by_channel: { online: 397, pos: 215, admin: 0, migration: 0 },
      },
      recent_activity: [
        programmeEntryFixture(),
        programmeEntryFixture({
          id: 9,
          entry_type: 'redemption',
          status: 'applied',
          channel: 'online',
          reference: '#10482',
          points: -200,
          order_name: '#10482',
          member: { id: 5, display_name: 'J. Dhillon', card_number: '0000-0005' },
        }),
      ],
    }),
  );
});

describe('the summary cards', () => {
  it('shows the six cards the agreed UI shows', async () => {
    renderDashboard();

    await screen.findByText('Total members');

    expect(card('Total members')).toContain('41,286');
    expect(card('Total members')).toContain('28,904 active · 12,382 lapsed');

    expect(card('New sign-ups (30d)')).toContain('612');
    expect(card('New sign-ups (30d)')).toContain('+18%');
    expect(card('New sign-ups (30d)')).toContain('397 online / 215 in-store');

    expect(card('Points issued (30d)')).toContain('184,520');
    expect(card('Points issued (30d)')).toContain('All qualifying items');

    expect(card('Pending points')).toContain('42,180');

    expect(card('Voucher value outstanding')).toContain('£120,500');

    expect(screen.getByText('Redemption rate (30d)')).toBeInTheDocument();
  });

  /**
   * Sprint 3 brings redemption, and until then there is no denominator. An
   * invented rate here is a number a director would quote in a meeting.
   */
  it('renders the redemption rate as an em dash, never a percentage', async () => {
    renderDashboard();

    await screen.findByText('Redemption rate (30d)');

    const rate = card('Redemption rate (30d)');

    expect(rate).toContain('—');
    expect(rate).not.toMatch(/\d+%/);
    // The amount actually redeemed is a real query, so it is shown.
    expect(rate).toContain('£16,120 redeemed');
    expect(rate).toContain('Sprint 3');
  });

  /**
   * D1: a member reward value is derived from their available points on read.
   * Nothing is issued, so there is no count of vouchers to quote.
   */
  it('says where the voucher value comes from rather than counting vouchers', async () => {
    renderDashboard();

    await screen.findByText('Voucher value outstanding');

    const voucher = card('Voucher value outstanding');

    expect(voucher).toContain('Derived from available points');
    expect(voucher).toContain('nothing is issued (D1)');
    expect(voucher).not.toMatch(/vouchers unredeemed/);
  });

  it('says nothing rather than zero when there is no previous month to compare', async () => {
    getOverview.mockResolvedValue(
      overviewFixture({
        enrolments: {
          last_30_days: 40,
          previous_30_days: 0,
          change_percent: null,
          by_channel: { online: 40, pos: 0, admin: 0, migration: 0 },
        },
      }),
    );

    renderDashboard();

    await screen.findByText('New sign-ups (30d)');

    expect(card('New sign-ups (30d)')).toContain('—');
    expect(card('New sign-ups (30d)')).not.toContain('0%');
  });

  it('names the date the oldest pending points clear', async () => {
    renderDashboard();

    expect(await screen.findByText('Oldest clears 14 Aug 2026')).toBeInTheDocument();
  });

  it('stamps the moment the figures were taken', async () => {
    renderDashboard();

    expect(await screen.findByText('Live figures · 13 Aug 2026, 09:42')).toBeInTheDocument();
  });
});

describe('the controls that are not built', () => {
  /**
   * The endpoint answers fixed windows, so the range control is shown inert
   * rather than offering a choice that would silently do nothing.
   */
  it('shows the date range and the export as coming, not as working', async () => {
    renderDashboard();

    await screen.findByText('Total members');

    expect(queryField('Date range')).toHaveAttribute('disabled');
    expect(screen.getByText('Export summary')).toHaveAttribute('disabled');
    expect(
      screen.getByText(/Other date ranges and the summary export arrive with Reports in Sprint 5/),
    ).toBeInTheDocument();
  });
});

describe('the recent activity strip', () => {
  it('shows the columns the agreed UI shows', async () => {
    renderDashboard();

    await findHeading('Recent loyalty activity');

    const headers = [...document.querySelectorAll('s-table-header')].map((cell) =>
      cell.textContent.trim(),
    );

    expect(headers).toEqual(['Member', 'Action', 'Channel', 'Points', 'Reference', 'Time']);

    const rows = [...document.querySelectorAll('s-table-body s-table-row')].map((row) =>
      [...row.querySelectorAll('s-table-cell')].map((cell) => cell.textContent.trim()),
    );

    expect(rows).toEqual([
      ['Margaret Whitfield', 'EarnPending', 'In-store', '+72', '#POS-8841', '09:31'],
      ['J. Dhillon', 'Redemption', 'Online', '−200', '#10482', '09:31'],
    ]);
  });

  it('links each row to the member and the strip to the ledger', async () => {
    renderDashboard();

    const member = await screen.findByRole('link', { name: 'Margaret Whitfield' });

    expect(member).toHaveAttribute('href', '/customers/1');

    // The agreed UI points this at Transactions, which Sprint 2 owns; until then
    // it goes to the programme ledger, which is the same movements.
    expect(
      screen.getByRole('link', { name: 'View the programme ledger' }),
    ).toHaveAttribute('href', '/loyalty');
  });

  it('says so plainly when nothing has happened yet', async () => {
    getOverview.mockResolvedValue(overviewFixture({ recent_activity: [] }));

    renderDashboard();

    expect(await screen.findByText('Nothing has happened yet')).toBeInTheDocument();
  });
});

describe('failure', () => {
  it('reports a refusal in the words the server used', async () => {
    getOverview.mockRejectedValue(new ApiError(500, 'request_failed', 'The database is away.'));

    renderDashboard();

    expect(await findHeading('The dashboard could not be loaded')).toBeInTheDocument();
    expect(screen.getByText('The database is away.')).toBeInTheDocument();
  });
});
