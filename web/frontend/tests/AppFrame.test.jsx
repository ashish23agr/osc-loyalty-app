import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { getOverview } from '../src/api/admin.js';
import { ApiError } from '../src/api/client.js';
import App from '../src/App.jsx';
import { NAVIGATION } from '../src/app/navigation.js';
import { findHeading, overviewFixture, queryHeading, sessionFor } from './support.jsx';

vi.mock('../src/api/admin.js', async () => (await import('./apiMock.js')).adminApiMock());

/**
 * The app frame: navigation, the session gate, and the promise that every area
 * of the agreed structure is reachable.
 *
 * App brings its own SessionProvider, so it is rendered directly here rather
 * than through the shared helper — what is under test is the gate itself.
 */
// The index route is the Dashboard, which every navigation test lands on.
beforeEach(() => {
  getOverview.mockResolvedValue(overviewFixture({ recent_activity: [] }));
});

function renderApp({ role = 'administrator', route = '/', loadSession } = {}) {
  return render(
    <MemoryRouter initialEntries={[route]}>
      <App loadSession={loadSession ?? (() => Promise.resolve(sessionFor(role)))} />
    </MemoryRouter>,
  );
}

describe('navigation', () => {
  it('offers every area of the agreed structure to an administrator', async () => {
    renderApp({ role: 'administrator' });

    for (const item of NAVIGATION) {
      expect(await screen.findByRole('link', { name: item.label })).toBeInTheDocument();
    }
  });

  it('leaves the audit log out for a role that cannot read it', async () => {
    renderApp({ role: 'agent' });

    expect(await screen.findByRole('link', { name: 'Customers' })).toBeInTheDocument();
    // The audit log names people and quotes their reasons; Manager floor.
    expect(screen.queryByRole('link', { name: 'Audit logs' })).not.toBeInTheDocument();
  });

  it('keeps the rules readable by a viewer, because reading them is a viewer permission', async () => {
    renderApp({ role: 'viewer' });

    expect(await screen.findByRole('link', { name: 'Settings' })).toBeInTheDocument();
  });

  it('puts the links in the App Bridge nav menu as well as on the page', async () => {
    renderApp({ role: 'administrator' });

    await screen.findByRole('link', { name: 'Customers' });

    const menu = document.querySelector('ui-nav-menu');

    expect(menu).not.toBeNull();
    // App Bridge treats the first anchor as the home link.
    expect(menu.querySelector('a')).toHaveAttribute('rel', 'home');
  });
});

describe('the session gate', () => {
  it('waits for the role before rendering a screen', () => {
    renderApp({ loadSession: () => new Promise(() => {}) });

    expect(screen.getByText('Checking your access…')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Customers' })).not.toBeInTheDocument();
  });

  it('explains a staff member who has not been given a role', async () => {
    renderApp({
      loadSession: () =>
        Promise.reject(
          new ApiError(403, 'no_role_assigned', 'This staff member has no Privilege Club role.'),
        ),
    });

    expect(await findHeading('You do not have a Privilege Club role yet')).toBeInTheDocument();
    expect(screen.getByText(/An Administrator needs to give you a role/)).toBeInTheDocument();
  });

  it('reports a failure to start in the words the server used', async () => {
    renderApp({
      loadSession: () =>
        Promise.reject(new ApiError(500, 'request_failed', 'The database is away.')),
    });

    expect(await findHeading('The console could not start')).toBeInTheDocument();
    expect(screen.getByText('The database is away.')).toBeInTheDocument();
  });
});

describe('screens that Sprint 1 has not built', () => {
  const stubs = [
    ['/vouchers', 'Voucher management'],
    ['/transactions', 'Transactions'],
    ['/reports', 'Reports'],
  ];

  it.each(stubs)('renders a placeholder at %s rather than a dead link', async (route, heading) => {
    renderApp({ route });

    expect(await findHeading('Not built yet')).toBeInTheDocument();
    expect(queryHeading(heading)).not.toBeNull();
  });

  it('says so for an address that is not part of the console at all', async () => {
    renderApp({ route: '/nowhere' });

    expect(await findHeading('Not found')).toBeInTheDocument();
  });
});

describe('the permission set', () => {
  it('answers an unknown permission with false rather than letting it through', async () => {
    renderApp({ role: 'administrator' });

    await screen.findByRole('link', { name: 'Customers' });

    const session = sessionFor('administrator');

    expect(session.permissions.manage_staff).toBe(true);
    expect(session.permissions.something_invented).toBeUndefined();
  });
});
