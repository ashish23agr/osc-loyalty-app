import { cloneElement, isValidElement } from 'react';

import { useSession } from './SessionProvider.jsx';

/**
 * Shows or disables an action according to the permission set from the server.
 *
 * This is user experience, never a control. Authorisation is the
 * `staff.role:{floor}` middleware on every endpoint, and it stays the enforcer;
 * what this prevents is the console offering a Viewer a button that would come
 * back 403.
 *
 * Two modes, because the right answer differs by case:
 *
 *   hide     — the action does not belong on this person's screen at all.
 *   disable  — the action belongs on the screen, and its absence would be
 *              confusing, so it is shown greyed out instead. Preferred for a
 *              primary action a colleague with another role clearly can use.
 *
 * A function child receives the decision, for the cases where neither mode
 * fits and the screen wants to word something itself.
 */
export default function RoleGate({
  permission,
  mode = 'hide',
  fallback = null,
  children,
}) {
  const { can } = useSession();
  const allowed = can(permission);

  if (typeof children === 'function') {
    return children(allowed);
  }

  if (allowed) {
    return children;
  }

  if (mode === 'disable' && isValidElement(children)) {
    // Only `disabled` is forced on; the child keeps everything else it was
    // given, so a screen can still disable the same button for its own reasons.
    return cloneElement(children, { disabled: true });
  }

  return fallback;
}
