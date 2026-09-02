/**
 * Does the browser actually have the Polaris web components?
 *
 * On 2026-08-27 the console shipped rendering as unstyled text in the embedded
 * admin. Every `s-*` element was an unknown element, so the browser laid their
 * text out inline with no cards, no table and no spacing — while the App Bridge
 * navigation in the admin chrome worked perfectly, which is what made it look
 * like a styling problem rather than a missing script.
 *
 * The cause: `app-bridge.js` registers only the App Bridge elements
 * (`ui-nav-menu`, `ui-modal`, `ui-title-bar`, `ui-save-bar`). The Polaris `s-*`
 * components come from a **second** script, `polaris.js`, which index.html did
 * not load. App Bridge reads `s-page` — that is why the assumption survived a
 * reading of its source — but it never defines it.
 *
 * Nothing in the test suite could catch this: jsdom does not register custom
 * elements at all, so an unregistered `s-page` renders its children there
 * exactly as a registered one would. Hence this guard, which runs in the one
 * place the truth is knowable: a real browser at boot.
 */
export const POLARIS_SCRIPT = 'https://cdn.shopify.com/shopifycloud/polaris.js';

export const APP_BRIDGE_SCRIPT = 'https://cdn.shopify.com/shopifycloud/app-bridge.js';

/** The element every screen is built on. If this is missing, so is the rest. */
const SENTINEL = 's-page';

export function polarisRegistered() {
  return typeof customElements !== 'undefined' && customElements.get(SENTINEL) !== undefined;
}

export function appBridgeLoaded() {
  return Boolean(globalThis.window?.shopify);
}

/**
 * Resolve once Polaris is registered, or false if it never arrives.
 *
 * The synchronous case is the normal one: polaris.js is a classic script in the
 * head, so it has already run by the time this deferred module does. The wait
 * exists so that changing the tag to `defer` or `async` later degrades into a
 * short pause rather than a false alarm on every load.
 */
export function waitForPolaris({ timeout = 3000 } = {}) {
  if (polarisRegistered()) {
    return Promise.resolve(true);
  }

  if (typeof customElements === 'undefined') {
    return Promise.resolve(false);
  }

  return Promise.race([
    customElements.whenDefined(SENTINEL).then(() => true),
    new Promise((resolve) => {
      setTimeout(() => resolve(polarisRegistered()), timeout);
    }),
  ]);
}

/**
 * The failure, as plain HTML.
 *
 * Deliberately not a single Polaris component and not React: the one thing
 * known to be true here is that `s-*` elements do not work, so a banner built
 * from them would be more text soup. Inline styles for the same reason — the
 * app ships no stylesheet of its own.
 *
 * It names the cause and the script, because the person reading it is a
 * developer looking at a broken screen, and "something went wrong" would cost
 * them the afternoon this cost us.
 */
export function renderRegistrationFailure(container, { scriptLoaded = appBridgeLoaded() } = {}) {
  if (!container) {
    return;
  }

  const box = document.createElement('div');

  box.setAttribute('role', 'alert');
  box.setAttribute('data-polaris-missing', 'true');
  box.style.cssText = [
    'margin:24px',
    'padding:16px 20px',
    'border:1px solid #8a6116',
    'border-left-width:4px',
    'border-radius:8px',
    'background:#fff5ea',
    'color:#3b2200',
    'font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif',
    'max-width:680px',
  ].join(';');

  const heading = document.createElement('strong');
  heading.style.cssText = 'display:block;font-size:15px;margin-bottom:8px';
  heading.textContent = 'The Polaris web components did not load';
  box.appendChild(heading);

  for (const line of [
    'Every s-* element on this page is an unknown element, so the app is showing '
      + 'its text with no layout. This is a missing script, not a styling fault.',
    `Check that ${POLARIS_SCRIPT} loaded: look for it in the Network tab, and for a `
      + 'blocked-request or 404 message in the console.',
    scriptLoaded
      ? 'App Bridge itself did load, so the admin navigation will look correct '
        + 'even though this page does not. The two come from different scripts.'
      : 'App Bridge did not load either, so check the network and the '
        + 'shopify-api-key meta tag as well.',
  ]) {
    const p = document.createElement('p');
    p.style.cssText = 'margin:0 0 8px';
    p.textContent = line;
    box.appendChild(p);
  }

  container.replaceChildren(box);
}
