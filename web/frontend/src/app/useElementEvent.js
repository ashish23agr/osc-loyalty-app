import { useLayoutEffect, useRef } from 'react';

/**
 * Subscribe to an event a Polaris web component emits.
 *
 * Polaris components dispatch real DOM events on the custom element — `input`
 * on a search field, `change` on a select, `nextpage` on a table. React's
 * synthetic event system does not know the custom ones, so they are wired with
 * addEventListener rather than an onSomething prop. Doing it through one hook
 * keeps that decision in a single place, and lets a test dispatch the same event
 * the browser would.
 *
 * Two details, both learned the hard way:
 *
 * **A layout effect, not a passive one.** A passive effect runs after the
 * browser has already been given the new DOM, so there is a window in which the
 * control is on screen and nothing is listening to it. Someone typing
 * immediately loses that first event. A layout effect runs synchronously as part
 * of the commit, so the control is never visible without its listener.
 *
 * **The listener is attached once and never re-attached.** A handler defined in
 * a component body is a new function on every render, so subscribing to it
 * directly would remove and re-add the listener on each one. Keeping the handler
 * in a ref and subscribing to a stable wrapper means the listener lives as long
 * as the element does, and always calls the current handler.
 */
export function useElementEvent(ref, eventName, handler) {
  const latest = useRef(handler);

  // Assigned during render rather than in an effect, so the wrapper always
  // reaches the handler belonging to the render the user is looking at.
  latest.current = handler;

  useLayoutEffect(() => {
    const element = ref.current;

    if (!element) {
      return undefined;
    }

    const listener = (event) => latest.current?.(event);

    element.addEventListener(eventName, listener);

    return () => element.removeEventListener(eventName, listener);
  }, [ref, eventName]);
}
