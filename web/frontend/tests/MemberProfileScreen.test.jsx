import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { getMember, getMemberLedger } from '../src/api/admin.js';
import { ApiError } from '../src/api/client.js';
import MemberProfileScreen from '../src/screens/MemberProfileScreen.jsx';
import {
  collection,
  findHeading,
  ledgerEntryFixture,
  memberFixture,
  renderWithSession,
} from './support.jsx';

vi.mock('../src/api/admin.js', async () => (await import('./apiMock.js')).adminApiMock());

/**
 * A3 — Customer profile.
 *
 * Margaret Whitfield with the agreed UI's numbers: 340 available, 72 pending,
 * £15 of voucher value, 60 points to the next £5. Those five figures are what
 * the balance cards promise and what a member of staff reads out over a
 * counter, so they are asserted exactly rather than loosely.
 */
function renderProfile({ role = 'manager' } = {}) {
  return renderWithSession(
    <Routes>
      <Route path="/customers/:id" element={<MemberProfileScreen />} />
    </Routes>,
    { role, route: '/customers/1' },
  );
}

beforeEach(() => {
  getMember.mockResolvedValue(memberFixture());
  getMemberLedger.mockResolvedValue(collection([ledgerEntryFixture()]));
});

/** The text of one balance card, found by its label. */
function card(label) {
  return screen.getByText(label).closest('s-box').textContent;
}

describe('the identity card', () => {
  it('shows the email as the primary identifier and the postcode as supporting', async () => {
    renderProfile();

    expect(await screen.findByText('Email — primary identifier')).toBeInTheDocument();
    expect(screen.getByText('m.whitfield@example.co.uk')).toBeInTheDocument();
    expect(screen.getByText('Postcode — supporting')).toBeInTheDocument();
    expect(screen.getByText('SP4 6AB')).toBeInTheDocument();
    expect(screen.getByText('0000-0001')).toBeInTheDocument();
    expect(screen.getByText('D-118422')).toBeInTheDocument();
  });

  it('shows a day-and-month birthday without inventing a year (MD2)', async () => {
    renderProfile();

    expect(await screen.findByText('12 Sep')).toBeInTheDocument();
  });

  it('marks a member with no email held, in red', async () => {
    getMember.mockResolvedValue(
      memberFixture({ email: null, has_no_email: true, display_name: 'Eileen Whitfield' }),
    );

    renderProfile();

    const badge = await screen.findByText('No email held');

    expect(badge.closest('s-badge')).toHaveAttribute('tone', 'critical');
  });

  it('distinguishes a member who declined from one who was never asked', async () => {
    const { unmount } = renderProfile();
    expect(await screen.findByText('Opted in')).toBeInTheDocument();
    unmount();

    getMember.mockResolvedValue(memberFixture({ email_marketing_consent: null }));
    renderProfile();

    // MD4 turns on exactly this distinction, so null is not rendered as "no".
    expect(await screen.findByText('Not asked')).toBeInTheDocument();
  });

  /**
   * The agreed UI shows a mobile number and a Klaviyo sync state. This
   * programme holds neither — Klaviyo is Sprint 4 — so both read as an em dash
   * rather than a plausible-looking value.
   */
  it('renders fields this programme does not hold as an em dash, never a guess', async () => {
    renderProfile();

    await screen.findByText('Mobile');

    const mobile = screen.getByText('Mobile').parentElement;

    expect(mobile.textContent).toContain('—');
    expect(screen.getByText(/Klaviyo sync/)).toHaveTextContent('—');
  });
});

describe('the balance cards', () => {
  it('shows the five figures the agreed UI shows', async () => {
    renderProfile();

    await screen.findByText('Available points (backend)');

    // Scoped to each card, because the same numbers appear in the ledger below:
    // 340 is both the available balance and the balance after the last movement.
    expect(card('Available points (backend)')).toContain('340');
    expect(card('Available points (backend)')).toContain('Not shown to the customer');

    expect(card('Pending points')).toContain('72');
    expect(card('Pending points')).toContain('Clears 12 Sep 2026 · 30-day period');

    expect(card('Available voucher value')).toContain('£15');
    expect(card('Available voucher value')).toContain('300 pts used · 40 pts carried forward');

    // The increment comes from the rule version, so the label is not hard-coded.
    expect(card('Points to next £5')).toContain('60');

    expect(card('Lifetime spend')).toContain('£4,182');
    expect(card('Lifetime spend')).toContain('Last purchase 9 Aug 2026');
  });

  it('says so rather than showing a date when nothing is pending', async () => {
    getMember.mockResolvedValue(
      memberFixture({
        pending_clears_at: null,
        balances: { ...memberFixture().balances, points_pending: 0 },
      }),
    );

    renderProfile();

    expect(await screen.findByText('Nothing pending')).toBeInTheDocument();
  });
});

describe('the points ledger tab', () => {
  it('shows every column the agreed UI shows, with the balance after the movement', async () => {
    renderProfile();

    // Wait for a movement, not for the tab label: the tab renders as soon as
    // the profile arrives, while the ledger is a second request behind it.
    await screen.findByText('#POS-8841');

    const headers = [...document.querySelectorAll('s-table-header')].map((cell) =>
      cell.textContent.trim(),
    );

    expect(headers).toEqual([
      'Date & time',
      'Type',
      'Detail',
      'Status',
      'Channel / staff',
      'Reference',
      'Points',
      'Available',
    ]);

    const row = [...document.querySelectorAll('s-table-body s-table-row')][0];
    const cells = [...row.querySelectorAll('s-table-cell')].map((cell) => cell.textContent.trim());

    expect(cells).toEqual([
      '13 Aug 09:31',
      'Earn',
      'Qualifying spend £72.40',
      'Pending to 12 Sep 2026',
      // The store is surfaced now that a till sale carries one. Only the id is
      // held; the location NAME needs a lookup nothing has built yet.
      'In-store · Store 6612440091',
      '#POS-8841',
      '+72',
      // The balance after this movement, not a total of what is on screen.
      '340',
    ]);
  });

  it('prefers the reason a person wrote over any derived wording', async () => {
    getMemberLedger.mockResolvedValue(
      collection([
        ledgerEntryFixture({
          entry_type: 'adjustment',
          status: 'available',
          channel: 'admin',
          reference: 'ADJ-4',
          reason: 'Goodwill gesture — replacement for a damaged acer',
          qualifying_value_pence: null,
          matures_at: null,
          points: 50,
          staff_reference: '900001',
          // An adjustment made in the console has no store, and the base
          // fixture's till location would be a fiction on this row.
          shopify_location_id: null,
        }),
      ]),
    );

    renderProfile();

    expect(
      await screen.findByText('Goodwill gesture — replacement for a damaged acer'),
    ).toBeInTheDocument();
    expect(screen.getByText('Console · Staff 900001')).toBeInTheDocument();
  });

  it('states that the ledger is immutable, as the agreed UI does', async () => {
    renderProfile();

    expect(
      await screen.findByText(/Every movement is an immutable ledger entry/),
    ).toBeInTheDocument();
  });

  it('reports a refusal in the words the server used', async () => {
    getMemberLedger.mockRejectedValue(new ApiError(422, 'validation_failed', 'Unknown type.'));

    renderProfile();

    expect(await findHeading('The movement history could not be loaded')).toBeInTheDocument();
  });
});

describe('the vouchers tab', () => {
  it('counts the issued vouchers on the tab and lists them when opened', async () => {
    getMember.mockResolvedValue(
      memberFixture({
        rewards: [
          {
            id: 1,
            reward_type: 'birthday',
            value_pence: 1000,
            currency: 'GBP',
            state: 'issued',
            birthday_year: 2026,
            issued_at: '2026-08-24T06:00:00+00:00',
            expires_at: '2026-11-22T06:00:00+00:00',
            redeemed_at: null,
            cancelled_at: null,
            cancelled_reason: null,
          },
        ],
      }),
    );

    renderProfile();

    const tab = await screen.findByText('Vouchers (1)');

    await userEvent.click(tab);

    await waitFor(() => expect(screen.getByText('Birthday reward')).toBeInTheDocument());
    expect(screen.getByText('£10')).toBeInTheDocument();
    expect(screen.getByText('24 Aug 2026')).toBeInTheDocument();

    // Scoped to the voucher table: "Active" is also this member's segment.
    const row = screen.getByText('Birthday reward').closest('s-table-row');

    expect(row.textContent).toContain('Active');
  });

  it('explains that the points-derived value is not one of these', async () => {
    renderProfile();

    await userEvent.click(await screen.findByText('Vouchers (0)'));

    expect(await screen.findByText('No vouchers issued')).toBeInTheDocument();
    expect(screen.getByText(/is not a voucher/)).toBeInTheDocument();
  });
});

describe('actions and states', () => {
  it('hides Adjust points from a viewer and gives an agent a working trigger', async () => {
    const { unmount } = renderProfile({ role: 'viewer' });

    await screen.findByText('Points ledger');
    expect(screen.queryByText('Adjust points')).not.toBeInTheDocument();
    // The modal is mounted only for a role that may adjust, so a Viewer has no
    // form sitting in the page waiting to be found.
    expect(document.querySelector('s-modal')).toBeNull();
    unmount();

    renderProfile({ role: 'agent' });

    const adjust = await screen.findByText('Adjust points');

    expect(adjust).not.toHaveAttribute('disabled');
    expect(adjust).toHaveAttribute('commandfor', 'adjust-points-modal');
    expect(document.querySelector('s-modal')).not.toBeNull();
  });

  it('warns that a merged account is a tombstone', async () => {
    getMember.mockResolvedValue(
      memberFixture({ is_merged: true, status: 'merged', merged_into_account_id: 9 }),
    );

    renderProfile();

    expect(await findHeading('This account was merged')).toBeInTheDocument();
    expect(screen.getByText(/Work on account 9 instead/)).toBeInTheDocument();
  });

  it('says so plainly when there is no such member', async () => {
    getMember.mockRejectedValue(new ApiError(404, 'not_found', 'No member with that id.'));

    renderProfile();

    expect(await findHeading('No such member')).toBeInTheDocument();
  });
});
