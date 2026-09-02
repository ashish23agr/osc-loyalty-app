import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import RoleGate from '../src/app/RoleGate.jsx';
import { renderWithSession, ROLES } from './support.jsx';

/**
 * RoleGate against the whole role model.
 *
 * `adjust_points` is the gated action worth asserting in full, because it is
 * the one the agreed UI puts a button on and the one with a real floor in the
 * middle of the four roles: an Agent and above may adjust, a Viewer may not.
 *
 * None of this is authorisation. The server refuses a Viewer at the middleware
 * whatever this component renders; what these tests protect is the console not
 * offering a button that would come back 403.
 */
describe('RoleGate on the Adjust points action', () => {
  // A marker alongside the gate, so a test can wait for the session to settle
  // before asserting that the button is absent.
  const gatedButton = (
    <>
      <s-text>Screen loaded</s-text>
      <RoleGate permission="adjust_points">
        <s-button>Adjust points</s-button>
      </RoleGate>
    </>
  );

  it.each(ROLES)('answers correctly for a %s', async (role) => {
    renderWithSession(gatedButton, { role });

    const allowed = ROLES.indexOf(role) >= ROLES.indexOf('agent');

    if (allowed) {
      expect(await screen.findByText('Adjust points')).toBeInTheDocument();
    } else {
      // The gate resolves after the session loads, so wait for the screen to
      // settle before asserting an absence.
      await screen.findByText('Screen loaded');
      expect(screen.queryByText('Adjust points')).not.toBeInTheDocument();
    }
  });

  it('hides the action from a viewer and shows it to an agent', async () => {
    const { unmount } = renderWithSession(gatedButton, { role: 'viewer' });
    await screen.findByText('Screen loaded');
    expect(screen.queryByText('Adjust points')).not.toBeInTheDocument();
    unmount();

    renderWithSession(gatedButton, { role: 'agent' });
    expect(await screen.findByText('Adjust points')).toBeInTheDocument();
  });
});

describe('RoleGate modes', () => {
  it('disables rather than hides when asked to', async () => {
    renderWithSession(
      <RoleGate permission="edit_rules" mode="disable">
        <s-button>Save rules</s-button>
      </RoleGate>,
      { role: 'manager' },
    );

    const button = await screen.findByText('Save rules');

    expect(button).toBeInTheDocument();
    expect(button).toHaveAttribute('disabled');
  });

  it('leaves the action alone when the role holds the permission', async () => {
    renderWithSession(
      <RoleGate permission="edit_rules" mode="disable">
        <s-button>Save rules</s-button>
      </RoleGate>,
      { role: 'administrator' },
    );

    expect(await screen.findByText('Save rules')).not.toHaveAttribute('disabled');
  });

  it('renders a fallback where one is given', async () => {
    renderWithSession(
      <RoleGate permission="view_audit" fallback={<s-text>Ask a Manager</s-text>}>
        <s-text>Audit log</s-text>
      </RoleGate>,
      { role: 'agent' },
    );

    expect(await screen.findByText('Ask a Manager')).toBeInTheDocument();
    expect(screen.queryByText('Audit log')).not.toBeInTheDocument();
  });

  it('hands the decision to a function child', async () => {
    renderWithSession(
      <RoleGate permission="manage_staff">
        {(allowed) => <s-text>{allowed ? 'yes' : 'no'}</s-text>}
      </RoleGate>,
      { role: 'manager' },
    );

    expect(await screen.findByText('no')).toBeInTheDocument();
  });

  /**
   * A permission the server has never heard of must fail closed. A typo that
   * silently granted access would be the worst possible failure mode for a
   * component whose whole job is deciding what to show.
   */
  it('refuses a permission it does not recognise', async () => {
    renderWithSession(
      <RoleGate permission="delete_the_ledger" fallback={<s-text>refused</s-text>}>
        <s-button>Delete the ledger</s-button>
      </RoleGate>,
      { role: 'administrator' },
    );

    expect(await screen.findByText('refused')).toBeInTheDocument();
    expect(screen.queryByText('Delete the ledger')).not.toBeInTheDocument();
  });
});
