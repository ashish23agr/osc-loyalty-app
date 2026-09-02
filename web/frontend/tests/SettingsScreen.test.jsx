import { fireEvent, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { getRules, saveRules } from '../src/api/admin.js';
import { ApiError } from '../src/api/client.js';
import SettingsScreen from '../src/screens/SettingsScreen.jsx';
import {
  fieldValue,
  findHeading,
  queryField,
  renderWithSession,
  rulePayloadFixture,
  rulesFixture,
} from './support.jsx';

vi.mock('../src/api/admin.js', async () => (await import('./apiMock.js')).adminApiMock());

/**
 * A10 — Settings, the rules engine.
 *
 * Two properties of the rules layer drive this screen and both are asserted
 * here: a version is a **full snapshot**, so every rule is sent whether or not
 * the form has a field for it; and a change is **audited with the old and new
 * value**, so the confirm step shows exactly what the log will record.
 */
function renderSettings({ role = 'administrator' } = {}) {
  return renderWithSession(<SettingsScreen />, { role, route: '/settings' });
}

/**
 * The Save button on the page, not the one inside the confirm modal. Both are
 * called "Save rules", which is the agreed wording in both places.
 */
function pageSaveButton() {
  return [...document.querySelectorAll('s-button')].find(
    (button) => button.textContent.trim() === 'Save rules' && button.closest('s-modal') === null,
  );
}

function modalSaveButton() {
  return [...document.querySelectorAll('s-modal s-button')].find(
    (button) => button.textContent.trim() === 'Save rules',
  );
}

function edit(label, value) {
  const field = queryField(label);

  field.value = String(value);
  fireEvent(field, new Event('input', { bubbles: true }));
}

beforeEach(() => {
  getRules.mockResolvedValue(rulesFixture());
});

describe('the form', () => {
  it('shows the fields from the agreed UI, with pence entered as pounds', async () => {
    renderSettings();

    await findHeading('Earning & rewards');

    expect(fieldValue('Earn rate')).toBe('1');
    expect(fieldValue('Voucher threshold')).toBe('100');
    // 500 pence is entered and shown as 5.
    expect(fieldValue('Voucher value')).toBe('5');
    expect(fieldValue('Birthday reward')).toBe('10');
    expect(fieldValue('Pending period')).toBe('30');
    expect(fieldValue('Points expiry')).toBe('182');

    await findHeading('Redemption rules');
    expect(queryField('Minimum basket spend')).not.toBeNull();
    expect(fieldValue('Maximum per order')).toBe('50');
    expect(queryField('Online redemption')).not.toBeNull();
    expect(queryField('POS redemption')).not.toBeNull();
  });

  it('states the unit it actually stores where the agreed UI says something else', async () => {
    renderSettings();

    await findHeading('Earning & rewards');

    // The agreed UI says "months from earning"; the stored unit is days and D2
    // starts the clock at availability. Both are said rather than converted.
    expect(screen.getByText('Days after points become available, per D2')).toBeInTheDocument();
    // One value drives the voucher increment and the redemption step.
    expect(
      screen.getByText(/This is also the redemption increment/),
    ).toBeInTheDocument();
  });

  /**
   * A full snapshot means these are saved whether or not anyone looked at them,
   * so they are shown. A field nobody can see is still a field they are saving.
   */
  it('lists the rules it carries forward without a field', async () => {
    renderSettings();

    await findHeading('Carried forward unchanged');

    expect(screen.getByText('expiry clock starts')).toBeInTheDocument();
    expect(screen.getByText('earn base')).toBeInTheDocument();
    expect(screen.getByText('reporting timezone')).toBeInTheDocument();
  });

  it('keeps Save inert until something has changed', async () => {
    renderSettings();

    await findHeading('Earning & rewards');

    expect(pageSaveButton()).toHaveAttribute('disabled');
    expect(screen.getByText('Discard')).toHaveAttribute('disabled');

    edit('Earn rate', 2);

    await waitFor(() => expect(pageSaveButton()).not.toHaveAttribute('disabled'));
  });

  /**
   * Asserted through the Save button rather than the field value: the test
   * typed into that field, so reading it back would partly be reading the
   * test's own keystroke. Whether the draft still differs from the saved
   * version is state React owns alone.
   */
  it('discards back to the saved version', async () => {
    renderSettings();

    await findHeading('Earning & rewards');
    edit('Earn rate', 2);

    await waitFor(() => expect(pageSaveButton()).not.toHaveAttribute('disabled'));

    fireEvent.click(screen.getByText('Discard'));

    await waitFor(() => expect(pageSaveButton()).toHaveAttribute('disabled'));
    expect(screen.getByText('Nothing has changed.')).toBeInTheDocument();
  });
});

describe('the confirm step', () => {
  /**
   * A diff, not a yes/no: a rule change is audited with the old and the new
   * value, and nobody should learn what they changed by reading the log
   * afterwards.
   */
  it('shows every changed rule as before and after', async () => {
    renderSettings();

    await findHeading('Earning & rewards');

    edit('Earn rate', 2);
    edit('Voucher value', 10);

    await findHeading('Save these rule changes?');

    const rows = [...document.querySelectorAll('s-modal s-table-body s-table-row')].map((row) =>
      [...row.querySelectorAll('s-table-cell')].map((cell) => cell.textContent.trim()),
    );

    expect(rows).toEqual([
      ['Earn rate', '1', '2'],
      // Shown in pounds, as it was entered.
      ['Voucher value', '£5', '£10'],
    ]);
  });

  it('offers a change summary, and says where it goes', async () => {
    renderSettings();

    await findHeading('Earning & rewards');
    edit('Earn rate', 2);

    expect(queryField('Change summary')).not.toBeNull();
    expect(
      screen.getByText('Written to the audit log alongside the old and new values.'),
    ).toBeInTheDocument();
  });

  it('sends the whole snapshot, not only what changed', async () => {
    saveRules.mockResolvedValue({ version: 2, payload: rulePayloadFixture() });

    renderSettings();

    await findHeading('Earning & rewards');
    edit('Earn rate', 2);

    fireEvent.click(modalSaveButton());

    await waitFor(() => expect(saveRules).toHaveBeenCalled());

    const [{ rules }] = saveRules.mock.calls[0];

    expect(rules.earn_points_per_pound).toBe(2);
    // Everything the form has no field for goes with it, because a partial
    // payload is rejected rather than merged.
    expect(Object.keys(rules).sort()).toEqual(Object.keys(rulePayloadFixture()).sort());
    expect(rules.expiry_clock_starts).toBe('availability');
    expect(rules.qualification).toEqual(rulePayloadFixture().qualification);
  });

  it('confirms the new version and says the old one still stands', async () => {
    saveRules.mockResolvedValue({ version: 2 });

    renderSettings();

    await findHeading('Earning & rewards');
    edit('Earn rate', 2);
    fireEvent.click(modalSaveButton());

    expect(await findHeading('Rules saved')).toBeInTheDocument();
    expect(
      screen.getByText(/Entries already posted keep the version they were calculated under/),
    ).toBeInTheDocument();
  });

  it('shows a rejected rule against its own field', async () => {
    saveRules.mockRejectedValue(
      new ApiError(422, 'validation_failed', 'Some of what was sent could not be accepted.', {
        points_expiry_days: [
          'Points must expire later than the pending period, or they mature and expire together.',
        ],
      }),
    );

    renderSettings();

    await findHeading('Earning & rewards');
    edit('Points expiry', 10);
    fireEvent.click(modalSaveButton());

    expect(
      await screen.findByText(
        'Points must expire later than the pending period, or they mature and expire together.',
      ),
    ).toBeInTheDocument();
  });
});

describe('the role floor', () => {
  /**
   * Reading the rules is a Viewer permission; saving them is an Administrator
   * one. A Manager therefore gets the screen with the form inert.
   */
  it('lets a manager read the rules and disables every control', async () => {
    renderSettings({ role: 'manager' });

    expect(await findHeading('You can read these rules but not change them')).toBeInTheDocument();
    expect(screen.getByText('Only an Administrator can save a new rule version.')).toBeInTheDocument();

    expect(queryField('Earn rate')).toHaveAttribute('disabled');
    expect(pageSaveButton()).toHaveAttribute('disabled');
  });

  it('gives an administrator the form live', async () => {
    renderSettings({ role: 'administrator' });

    await findHeading('Earning & rewards');

    expect(queryField('Earn rate')).not.toHaveAttribute('disabled');
    expect(screen.queryByText('Only an Administrator can save a new rule version.')).toBeNull();
  });

  it('reports a refusal from the server in its own words', async () => {
    getRules.mockRejectedValue(new ApiError(500, 'request_failed', 'The rules table is away.'));

    renderSettings();

    expect(await findHeading('The rules could not be loaded')).toBeInTheDocument();
    expect(screen.getByText('The rules table is away.')).toBeInTheDocument();
  });
});
