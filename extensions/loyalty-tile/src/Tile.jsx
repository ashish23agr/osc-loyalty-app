import '@shopify/ui-extensions/preact';
import {render} from 'preact';
import {useEffect, useState} from 'preact/hooks';

import {isConnected, tileState} from './lib/tileState.js';

export default async () => {
  render(<Extension />, document.body);
};

/**
 * The Privilege Club tile on the POS home screen (M7, C7).
 *
 * Deliberately almost nothing: a heading, a subheading, and whether it opens.
 * Everything the till can actually do lives in the modal, and everything the
 * tile has to DECIDE lives in `tileState`, which is a pure function and is
 * tested as one.
 *
 * The state that matters most is the offline one. C7 is confirmed: loyalty needs
 * a live connection, so the tile refuses rather than guessing at a balance — but
 * it refuses in words that make clear the SALE continues. An assistant who reads
 * a dead tile as a broken till is the failure this wording exists to prevent.
 */
function Extension() {
  const [connected, setConnected] = useState(isConnected(shopify.connectivity?.current?.value));
  const [session, setSession] = useState(shopify.session?.currentSession ?? null);

  useEffect(() => {
    // POS reports connectivity as a signal, so this follows it rather than
    // sampling once at render and going stale.
    const unsubscribe = shopify.connectivity?.current?.subscribe?.((state) => {
      setConnected(isConnected(state));
    });

    setSession(shopify.session?.currentSession ?? null);

    return () => unsubscribe?.();
  }, []);

  const state = tileState({connected, session});

  return (
    <s-tile
      heading={state.heading}
      subheading={state.subheading}
      enabled={state.enabled}
      onClick={state.enabled ? () => shopify.action.presentModal() : undefined}
    />
  );
}
