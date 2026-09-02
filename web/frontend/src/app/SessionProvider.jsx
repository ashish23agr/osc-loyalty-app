import { createContext, useContext, useEffect, useMemo, useState } from 'react';

import { getMe } from '../api/admin.js';
import { ApiError } from '../api/client.js';

/**
 * Who the console is talking to.
 *
 * One call to GET /api/admin/me before any screen renders, because every screen
 * needs the role and there is no sensible default to fall back on. The
 * permission set comes from the server as named capabilities rather than a role
 * string, so the console never restates the role model — it asks whether this
 * person may adjust points, and the server has already decided.
 *
 * This is not authorisation. Every endpoint is guarded by its own role floor
 * server-side; this is what stops the console offering a button that would then
 * be refused.
 */
const SessionContext = createContext(null);

export function SessionProvider({ children, loadSession }) {
  const [state, setState] = useState({ status: 'loading' });
  // A default parameter would be a new function identity on every render and
  // re-run the effect for ever; the fallback has to be inside it.
  const load = loadSession ?? getMe;

  useEffect(() => {
    let cancelled = false;

    Promise.resolve(load())
      .then((session) => {
        if (!cancelled) {
          setState({ status: 'ready', session });
        }
      })
      .catch((error) => {
        if (!cancelled) {
          setState({ status: 'error', error });
        }
      });

    return () => {
      cancelled = true;
    };
  }, [load]);

  const value = useMemo(() => {
    const session = state.session ?? null;

    return {
      ...state,
      session,
      staff: session?.staff ?? null,
      permissions: session?.permissions ?? {},
      shopDomain: session?.shop_domain ?? null,
      currency: session?.currency ?? 'GBP',
      // Unknown permissions answer false. A capability the server has not
      // granted is one the console must not offer, and a typo in a permission
      // name must fail closed rather than open.
      can: (permission) => session?.permissions?.[permission] === true,
    };
  }, [state]);

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession() {
  const context = useContext(SessionContext);

  if (context === null) {
    throw new Error('useSession must be used inside a SessionProvider.');
  }

  return context;
}

/**
 * The three ways the first call can end, as a screen.
 *
 * A staff member with no role is the one worth wording carefully: it is the
 * expected first-run state on a shop an Administrator has not configured, not a
 * fault, and telling them to contact an Administrator is the whole fix.
 */
export function SessionGate({ children }) {
  const { status, error } = useSession();

  if (status === 'loading') {
    return (
      <s-page heading="Privilege Club">
        <s-section>
          <s-stack direction="inline" gap="base" alignItems="center">
            <s-spinner size="base" accessibilityLabel="Loading your Privilege Club role" />
            <s-text>Checking your access…</s-text>
          </s-stack>
        </s-section>
      </s-page>
    );
  }

  if (status === 'error') {
    const roleMissing = error instanceof ApiError && error.isRoleMissing;

    return (
      <s-page heading="Privilege Club">
        <s-section>
          <s-banner
            heading={roleMissing ? 'You do not have a Privilege Club role yet' : 'The console could not start'}
            tone={roleMissing ? 'info' : 'critical'}
          >
            <s-paragraph>
              {roleMissing
                ? 'An Administrator needs to give you a role before you can use the Privilege Club console.'
                : error?.message}
            </s-paragraph>
          </s-banner>
        </s-section>
      </s-page>
    );
  }

  return children;
}
