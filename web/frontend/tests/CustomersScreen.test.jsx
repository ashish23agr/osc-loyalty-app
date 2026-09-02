import { screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { listMembers } from '../src/api/admin.js';
import { ApiError } from '../src/api/client.js';
import CustomersScreen from '../src/screens/CustomersScreen.jsx';
import {
  collection,
  findHeading,
  memberRowFixture,
  queryByAttribute,
  queryField,
  renderWithSession,
} from './support.jsx';

vi.mock('../src/api/admin.js', async () => (await import('./apiMock.js')).adminApiMock());

/**
 * A2 — Customers, search and list.
 *
 * The three Whitfields from the agreed UI, because they are the case that
 * matters: two share a postcode, one holds no email address at all, and all
 * three have to be reachable from the same search box (MD1).
 */
const MARGARET = memberRowFixture();

const THOMAS = memberRowFixture({
  id: 2,
  card_number: '0000-0002',
  legacy_card_number: null,
  email: 'tomw@example.co.uk',
  first_name: 'Thomas',
  display_name: 'Thomas Whitfield',
  enrolled_at: '2024-06-02T09:00:00+00:00',
  points_available: 85,
  points_pending: 0,
  voucher_balance_pence: 0,
});

const EILEEN = memberRowFixture({
  id: 3,
  card_number: '0000-0003',
  legacy_card_number: 'D-090311',
  email: null,
  has_no_email: true,
  first_name: 'Eileen',
  display_name: 'Eileen Whitfield',
  postcode: 'BH21 4RG',
  segment: 'lapsed',
  enrolled_at: '2016-09-08T09:00:00+00:00',
  points_available: 1120,
  points_pending: 0,
  voucher_balance_pence: 5500,
});

beforeEach(() => {
  listMembers.mockResolvedValue(collection([MARGARET, THOMAS, EILEEN]));
});

describe('the customer list', () => {
  it('shows every column the agreed UI shows', async () => {
    renderWithSession(<CustomersScreen />, { role: 'manager' });

    expect(await screen.findByText('Margaret Whitfield')).toBeInTheDocument();

    // Scoped to the header row: "Postcode" is also one of the search-field
    // options, and an unscoped text query would match both.
    const headers = [...document.querySelectorAll('s-table-header')].map((cell) =>
      cell.textContent.trim(),
    );

    expect(headers).toEqual([
      'Member',
      'Email — primary ID',
      'Postcode',
      'Card no.',
      'Legacy card',
      'Available',
      'Pending',
      'Voucher value',
      'Segment',
      '',
    ]);

    expect(screen.getByText('m.whitfield@example.co.uk')).toBeInTheDocument();
    expect(screen.getByText('0000-0001')).toBeInTheDocument();
    expect(screen.getByText('D-118422')).toBeInTheDocument();
    expect(screen.getByText('1,120')).toBeInTheDocument();
    expect(screen.getByText('£55')).toBeInTheDocument();
    expect(screen.getByText('joined 14 Mar 2019')).toBeInTheDocument();
  });

  /**
   * MD1, and the reason this screen exists in the shape it does. A blank cell
   * would read as data that failed to load; the agreed UI puts it in red, which
   * is a critical badge here.
   */
  it('marks a member with no email held, in red, rather than leaving the cell blank', async () => {
    renderWithSession(<CustomersScreen />, { role: 'viewer' });

    const badge = await screen.findByText('No email held');

    expect(badge).toBeInTheDocument();
    expect(badge.closest('s-badge')).toHaveAttribute('tone', 'critical');
  });

  it('offers the search field and every filter from the agreed UI', async () => {
    renderWithSession(<CustomersScreen />, { role: 'viewer' });

    await screen.findByText('Margaret Whitfield');

    expect(queryField('Search members')).not.toBeNull();

    const options = [...document.querySelectorAll('s-option')].map((option) =>
      option.textContent.trim(),
    );

    // The field list from the agreed UI, in its order and wording.
    expect(options).toEqual(
      expect.arrayContaining([
        'Search: all fields',
        'Email — primary identifier',
        'Name',
        'Postcode',
        'Card number (new)',
        'Legacy card number',
        'Segment: all',
        'Enrolled: any channel',
      ]),
    );
  });

  it('states the identifier rule the agreed UI states', async () => {
    renderWithSession(<CustomersScreen />, { role: 'viewer' });

    expect(
      await screen.findByText(/Email is the primary customer identifier/),
    ).toBeInTheDocument();
  });
});

describe('searching', () => {
  it('sends the query, the field and the filters the URL is carrying', async () => {
    renderWithSession(<CustomersScreen />, {
      role: 'viewer',
      route: '/customers?q=whitfield&field=legacy_card&segment=lapsed&channel=migration',
    });

    await screen.findByText('Margaret Whitfield');

    await waitFor(() =>
      expect(listMembers).toHaveBeenCalledWith(
        expect.objectContaining({
          q: 'whitfield',
          field: 'legacy_card',
          segment: 'lapsed',
          channel: 'migration',
        }),
      ),
    );
  });

  it('says so when nothing matches, and points at the searches that do not need an email', async () => {
    listMembers.mockResolvedValue(collection([]));

    renderWithSession(<CustomersScreen />, { role: 'viewer' });

    expect(await screen.findByText('No members match that search')).toBeInTheDocument();
    expect(screen.getByText(/A member with no email address is found the same way/)).toBeInTheDocument();
  });

  it('reports a refusal in the words the server used', async () => {
    listMembers.mockRejectedValue(new ApiError(422, 'validation_failed', 'Unknown segment.'));

    renderWithSession(<CustomersScreen />, { role: 'viewer' });

    expect(await findHeading('The customer list could not be loaded')).toBeInTheDocument();
    expect(screen.getByText('Unknown segment.')).toBeInTheDocument();
  });
});

describe('actions', () => {
  it('hides enrolment from a viewer and shows it, disabled, to an agent', async () => {
    const { unmount } = renderWithSession(<CustomersScreen />, { role: 'viewer' });

    await screen.findByText('Margaret Whitfield');
    expect(screen.queryByText('Enrol member')).not.toBeInTheDocument();
    unmount();

    renderWithSession(<CustomersScreen />, { role: 'agent' });

    const enrol = await screen.findByText('Enrol member');

    expect(enrol).toBeInTheDocument();
    // The enrolment modal is not built yet, so the button is present for the
    // layout and disabled rather than dead.
    expect(enrol).toHaveAttribute('disabled');
  });

  it('gives every row a way through to the profile', async () => {
    renderWithSession(<CustomersScreen />, { role: 'viewer' });

    await screen.findByText('Margaret Whitfield');

    // accessibilityLabel reaches an unregistered custom element as a plain
    // attribute, so it is read rather than resolved as an accessible name.
    expect(queryByAttribute('accessibilitylabel', 'Open Margaret Whitfield')).not.toBeNull();
    expect(screen.getAllByText('Open')).toHaveLength(3);
  });
});
