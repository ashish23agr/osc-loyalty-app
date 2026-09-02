import { fireEvent, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { listAudit } from '../src/api/admin.js';
import { ApiError } from '../src/api/client.js';
import AuditScreen from '../src/screens/AuditScreen.jsx';
import {
  auditEntryFixture,
  collection,
  findHeading,
  queryField,
  renderWithSession,
} from './support.jsx';

vi.mock('../src/api/admin.js', async () => (await import('./apiMock.js')).adminApiMock());

/**
 * A12 — Audit log.
 *
 * Manager floor, and the refusal below it is a state this screen has to render
 * properly: a bookmark or a shared link is a legitimate way to arrive without
 * the role, and an empty table would read as "nothing ever happened".
 */
const FILTERS = {
  actions: ['points.adjusted', 'rules.saved', 'account.enrolled'],
  actor_types: ['staff', 'system', 'pos', 'migration'],
  channels: ['admin', 'pos', 'account', 'system'],
  actors: [
    { staff_id: 100000001, name: 'N. Mehra' },
    { staff_id: 100000002, name: 'Ellie R.' },
  ],
};

function renderAudit({ role = 'manager', route = '/audit' } = {}) {
  return renderWithSession(<AuditScreen />, { role, route });
}

beforeEach(() => {
  listAudit.mockResolvedValue(collection([auditEntryFixture()], { filters: FILTERS }));
});

describe('the log', () => {
  it('shows every column the agreed UI shows', async () => {
    renderAudit();

    await screen.findByText('13 Aug 09:44');

    const headers = [...document.querySelectorAll('s-table-header')].map((cell) =>
      cell.textContent.trim(),
    );

    expect(headers).toEqual([
      'Timestamp',
      'User',
      'Role',
      'Action',
      'Target',
      'Before → after',
      'Reason',
    ]);

    const cells = [...document.querySelectorAll('s-table-body s-table-cell')].map((cell) =>
      cell.textContent.trim(),
    );

    expect(cells[0]).toBe('13 Aug 09:44');
    expect(cells[1]).toBe('N. Mehra');
    // The agreed UI writes the manager role as "Loyalty manager".
    expect(cells[2]).toBe('Loyalty manager');
    expect(cells[3]).toBe('Points adjustment');
    // A member is named by their card number, as the agreed UI names them.
    expect(cells[4]).toBe('0000-0001');
    expect(cells[5]).toContain('points available: 340 → 390');
    expect(cells[6]).toBe('Goodwill gesture — damaged acer (#4471)');
  });

  it('writes an em dash for an actor that holds no role', async () => {
    listAudit.mockResolvedValue(
      collection(
        [
          auditEntryFixture({
            actor: { type: 'system', staff_id: null, name: null, role: null },
            action: 'migration.committed',
            reason: null,
          }),
        ],
        { filters: FILTERS },
      ),
    );

    renderAudit();

    await screen.findByText('Migration');

    const cells = [...document.querySelectorAll('s-table-body s-table-cell')].map((cell) =>
      cell.textContent.trim(),
    );

    expect(cells[1]).toBe('System');
    expect(cells[2]).toBe('—');
    expect(cells[6]).toBe('—');
  });

  it('states that entries cannot be edited or deleted', async () => {
    renderAudit();

    expect(
      await screen.findByText('Entries cannot be edited or deleted from the interface.'),
    ).toBeInTheDocument();
  });
});

describe('the filters', () => {
  it('offers user, action and a date range, built from what has happened', async () => {
    renderAudit();

    await screen.findByText('13 Aug 09:44');

    expect(queryField('Search the audit log')).not.toBeNull();
    expect(queryField('From')).not.toBeNull();
    expect(queryField('To')).not.toBeNull();

    const options = [...document.querySelectorAll('s-option')].map((one) => one.textContent.trim());

    expect(options).toEqual(
      expect.arrayContaining([
        'Action: all',
        'Points adjustment',
        'Rule change',
        'Enrolment',
        'User: all',
        // Read from the entries, so someone whose role was revoked stays
        // selectable — which is exactly when they matter.
        'N. Mehra',
        'Ellie R.',
      ]),
    );
  });

  it('sends the filters the URL is carrying', async () => {
    renderAudit({
      route: '/audit?q=acer&action=points.adjusted&actor=100000001&from=2026-08-01&to=2026-08-13',
    });

    await screen.findByText('13 Aug 09:44');

    await waitFor(() =>
      expect(listAudit).toHaveBeenCalledWith(
        expect.objectContaining({
          q: 'acer',
          action: 'points.adjusted',
          actorStaffId: '100000001',
          from: '2026-08-01',
          to: '2026-08-13',
        }),
      ),
    );
  });

  it('puts a chosen filter into the URL so the view is a link', async () => {
    renderAudit();

    await screen.findByText('13 Aug 09:44');

    const select = queryField('Action');

    select.value = 'rules.saved';
    fireEvent(select, new Event('change', { bubbles: true }));

    await waitFor(() =>
      expect(listAudit).toHaveBeenLastCalledWith(
        expect.objectContaining({ action: 'rules.saved' }),
      ),
    );
  });

  it('says so when nothing matches', async () => {
    listAudit.mockResolvedValue(collection([], { filters: FILTERS }));

    renderAudit();

    expect(await screen.findByText('Nothing matches those filters')).toBeInTheDocument();
  });
});

describe('the role floor', () => {
  /**
   * A direct hit without the Manager role gets the server's own refusal,
   * rendered as a screen — not a crash, and not an empty table.
   */
  it('renders the refusal cleanly for a role below the floor', async () => {
    listAudit.mockRejectedValue(
      new ApiError(403, 'role_floor_not_met', 'This action needs the manager role or higher.', {
        required: 'manager',
        held: 'agent',
      }),
    );

    renderAudit({ role: 'agent' });

    expect(await findHeading('You cannot see the audit log')).toBeInTheDocument();
    expect(screen.getByText('This action needs the manager role or higher.')).toBeInTheDocument();
    expect(
      screen.getByText('It needs the Loyalty manager role; you hold Agent.'),
    ).toBeInTheDocument();

    // No table, so nothing reads as "nothing ever happened".
    expect(document.querySelector('s-table')).toBeNull();
  });
});
