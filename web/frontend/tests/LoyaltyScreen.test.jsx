import { fireEvent, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { getOverview, getRules, listProgrammeLedger } from '../src/api/admin.js';
import { ApiError } from '../src/api/client.js';
import LoyaltyScreen from '../src/screens/LoyaltyScreen.jsx';
import {
  collection,
  findHeading,
  overviewFixture,
  programmeEntryFixture,
  queryField,
  renderWithSession,
  rulesFixture,
  summaryFixture,
} from './support.jsx';

vi.mock('../src/api/admin.js', async () => (await import('./apiMock.js')).adminApiMock());

/**
 * A5 — Loyalty.
 *
 * The tiles, the programme ledger and the rules in force. Several sub-figures in
 * the agreed UI are things this system does not hold yet, and the rule for those
 * is the same as everywhere else: an em dash, never a plausible number.
 */
function renderLoyalty({ role = 'viewer', route = '/loyalty' } = {}) {
  return renderWithSession(<LoyaltyScreen />, { role, route });
}

/** The text of one summary tile, found by its label. */
function tile(label) {
  return screen.getByText(label).closest('s-box').textContent;
}

beforeEach(() => {
  listProgrammeLedger.mockResolvedValue(
    collection([programmeEntryFixture()], { summary: summaryFixture() }),
  );
  getOverview.mockResolvedValue(overviewFixture());
  getRules.mockResolvedValue(rulesFixture());
});

describe('the summary tiles', () => {
  it('shows the five tiles the agreed UI shows', async () => {
    renderLoyalty();

    await screen.findByText('Points in circulation');

    expect(tile('Points in circulation')).toContain('2,410,000');
    expect(tile('Points in circulation')).toContain('£120,500 of voucher value');

    expect(tile('Earned today')).toContain('6,140');
    expect(tile('Pending (not yet available)')).toContain('42,180');
    expect(tile('Expiring in 30 days')).toContain('18,240');
    expect(tile('Reversals today')).toContain('−214');
  });

  /**
   * The three sub-figures that were em dashes through Sprint 1 are real now,
   * because Sprint 2's engine produces them. Each is asserted from the API
   * value rather than restated in the component.
   */
  it('names the qualifying spend behind today earning', async () => {
    renderLoyalty();

    await screen.findByText('Earned today');

    expect(tile('Earned today')).toContain('£6,140 of qualifying spend');
  });

  it('names the next expiry run, read from the schedule', async () => {
    renderLoyalty();

    await screen.findByText('Earned today');

    expect(tile('Expiring in 30 days')).toContain('Next run 14 Aug 02:10');
  });

  it('splits today reversals between refunds and cancellations', async () => {
    renderLoyalty();

    await screen.findByText('Earned today');

    expect(tile('Reversals today')).toContain('9 from refunds');
    expect(tile('Reversals today')).toContain('2 from cancellations');
  });

  /** A quiet day is said plainly, not reported as nought and nought. */
  it('says there were no reversals rather than showing two zeroes', async () => {
    listProgrammeLedger.mockResolvedValue(
      collection([programmeEntryFixture()], {
        summary: summaryFixture({
          points_reversed_today: 0,
          reversals_today: {
            refunds: { entries: 0, points: 0 },
            cancellations: { entries: 0, points: 0 },
          },
        }),
      }),
    );

    renderLoyalty();

    await screen.findByText('Earned today');

    expect(tile('Reversals today')).toContain('No reversals today');
  });

  /** The em-dash rule still stands where the programme holds nothing. */
  it('falls back to an em dash when the reversal split is absent', async () => {
    listProgrammeLedger.mockResolvedValue(
      collection([programmeEntryFixture()], {
        summary: summaryFixture({ reversals_today: undefined }),
      }),
    );

    renderLoyalty();

    await screen.findByText('Earned today');

    expect(tile('Reversals today')).toContain('—');
  });

  /** C12: the audit log is what makes "when did it last run" answerable. */
  it('names when each scheduled sweep last ran', async () => {
    renderLoyalty();

    expect(await screen.findByText(/Maturity last ran 13 Aug 09:00/)).toBeInTheDocument();
    expect(screen.getByText(/Expiry last ran 13 Aug 02:10/)).toBeInTheDocument();
  });

  it('says so when no scheduled run has ever been recorded', async () => {
    getOverview.mockResolvedValue(
      overviewFixture({
        jobs: {
          overdue_maturities: { entries: 0, points: 0 },
          overdue_expiries: { lots: 0, points: 0 },
          oldest_pending_clears_at: null,
          oldest_cache_rebuilt_at: null,
          last_runs: {},
          next_expiry_run_at: null,
        },
      }),
    );

    renderLoyalty();

    expect(
      await screen.findByText(/No scheduled run has been recorded yet/),
    ).toBeInTheDocument();
  });

  it('names the date the oldest pending points clear, which it does hold', async () => {
    renderLoyalty();

    expect(await screen.findByText('Oldest clears 14 Aug 2026')).toBeInTheDocument();
  });

  it('says plainly when the scheduled work is keeping up', async () => {
    renderLoyalty();

    expect(
      await screen.findByText(/every matured earning and every expired lot has been processed/),
    ).toBeInTheDocument();
  });

  /**
   * Counted from the ledger, so a queue that silently stopped shows as work
   * piling up — the failure this programme is most exposed to and the one with
   * no other visible symptom.
   */
  it('warns when maturities or expiries are overdue', async () => {
    getOverview.mockResolvedValue(
      overviewFixture({
        jobs: {
          overdue_maturities: { entries: 12, points: 1840 },
          overdue_expiries: { lots: 3, points: 220 },
          oldest_pending_clears_at: null,
          oldest_cache_rebuilt_at: null,
        },
      }),
    );

    renderLoyalty();

    expect(await findHeading('Scheduled work is behind')).toBeInTheDocument();
    expect(screen.getByText(/1,840 points in 12 earnings/)).toBeInTheDocument();
  });
});

describe('the programme ledger', () => {
  it('shows the columns the agreed UI shows, and names the member on every row', async () => {
    renderLoyalty();

    await screen.findByText('Margaret Whitfield');

    const headers = [...document.querySelectorAll('s-table-header')].map((cell) =>
      cell.textContent.trim(),
    );

    expect(headers).toEqual(['Time', 'Member', 'Type', 'Channel', 'Delta']);

    const cells = [...document.querySelectorAll('s-table-body s-table-cell')].map((cell) =>
      cell.textContent.trim(),
    );

    expect(cells).toEqual(['13 Aug 09:31', 'Margaret Whitfield', 'Earn', 'In-store', '+72']);
  });

  it('links each row through to the member', async () => {
    renderLoyalty();

    const link = await screen.findByRole('link', { name: 'Margaret Whitfield' });

    expect(link).toHaveAttribute('href', '/customers/1');
  });

  it('sends the type and channel filters the URL is carrying', async () => {
    renderLoyalty({ route: '/loyalty?type=earn&channel=pos' });

    await screen.findByText('Margaret Whitfield');

    await waitFor(() =>
      expect(listProgrammeLedger).toHaveBeenCalledWith(
        expect.objectContaining({ type: 'earn', channel: 'pos' }),
      ),
    );
  });

  it('puts a chosen filter into the URL so the view is a link', async () => {
    renderLoyalty();

    await screen.findByText('Margaret Whitfield');

    const select = queryField('Entry type');

    select.value = 'adjustment';
    fireEvent(select, new Event('change', { bubbles: true }));

    await waitFor(() =>
      expect(listProgrammeLedger).toHaveBeenLastCalledWith(
        expect.objectContaining({ type: 'adjustment' }),
      ),
    );
  });

  it('says so when nothing matches the filters', async () => {
    listProgrammeLedger.mockResolvedValue(collection([], { summary: summaryFixture() }));

    renderLoyalty();

    expect(await screen.findByText('No movements match those filters')).toBeInTheDocument();
  });

  it('reports a refusal in the words the server used', async () => {
    listProgrammeLedger.mockRejectedValue(
      new ApiError(422, 'validation_failed', 'Unknown channel.'),
    );

    renderLoyalty();

    expect(await findHeading('The programme ledger could not be loaded')).toBeInTheDocument();
  });
});

describe('the rules in force', () => {
  /**
   * Every line is the stored value rather than the wording of the agreed UI, so
   * changing a rule in Settings changes this panel and the two cannot drift.
   */
  it('reads the current rule version rather than restating the launch values', async () => {
    renderLoyalty();

    await findHeading('Rules currently in force');

    expect(screen.getByText('£1 = 1 point')).toBeInTheDocument();
    expect(screen.getByText('pending for 30 days')).toBeInTheDocument();
    expect(screen.getByText('100 points = £5')).toBeInTheDocument();
    expect(screen.getByText('£50 per order')).toBeInTheDocument();
    expect(screen.getByText(/Version 1\. Changed only from Settings/)).toBeInTheDocument();
  });

  it('follows the saved rules when they are not the launch ones', async () => {
    getRules.mockResolvedValue(
      rulesFixture({
        current: {
          ...rulesFixture().current,
          version: 4,
          payload: { ...rulesFixture().current.payload, earn_points_per_pound: 2 },
        },
      }),
    );

    renderLoyalty();

    expect(await screen.findByText('£1 = 2 points')).toBeInTheDocument();
    expect(screen.getByText(/Version 4\./)).toBeInTheDocument();
  });
});

describe('getting to the rules', () => {
  it('links Rules settings to the Settings screen', async () => {
    renderLoyalty();

    await screen.findByText('Margaret Whitfield');

    expect(screen.getByText('Rules settings').closest('a')).toHaveAttribute('href', '/settings');
  });
});
