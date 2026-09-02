import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import {
  APP_BRIDGE_SCRIPT,
  POLARIS_SCRIPT,
  polarisRegistered,
  renderRegistrationFailure,
  waitForPolaris,
} from '../src/app/polarisGuard.js';

/**
 * The guard against the failure that shipped on 2026-08-27: the console
 * rendering as unstyled text because `polaris.js` was never loaded and every
 * `s-*` element stayed unknown.
 *
 * Two halves, because one alone would not have caught it. The document test
 * asserts the script tag is in index.html at all — the actual regression. The
 * runtime tests assert that if it ever fails to run, the app says so instead of
 * rendering text soup.
 */
// Resolved from the vitest root, which is this package directory.
const INDEX_HTML = readFileSync(resolve(process.cwd(), 'index.html'), 'utf8');

describe('index.html', () => {
  /**
   * The regression itself. app-bridge.js registers ui-nav-menu and friends;
   * polaris.js registers every s-* element. Loading only the first is what
   * broke, and it broke invisibly because the admin navigation still worked.
   */
  it('loads both Shopify scripts, not just App Bridge', () => {
    expect(INDEX_HTML).toContain(APP_BRIDGE_SCRIPT);
    expect(INDEX_HTML).toContain(POLARIS_SCRIPT);
  });

  it('puts the api-key meta before App Bridge, which requires it', () => {
    expect(INDEX_HTML.indexOf('name="shopify-api-key"')).toBeGreaterThan(-1);
    expect(INDEX_HTML.indexOf('name="shopify-api-key"')).toBeLessThan(
      INDEX_HTML.indexOf(APP_BRIDGE_SCRIPT),
    );
  });

  /**
   * Both are classic scripts, so they run before the app bundle, which Vite
   * emits as a module and therefore defers. A `defer` or `async` here would put
   * the app back in a race it cannot see itself losing.
   */
  it('leaves both scripts blocking, so they run before the app bundle', () => {
    for (const script of [APP_BRIDGE_SCRIPT, POLARIS_SCRIPT]) {
      const tag = INDEX_HTML.slice(
        INDEX_HTML.lastIndexOf('<script', INDEX_HTML.indexOf(script)),
        INDEX_HTML.indexOf('</script>', INDEX_HTML.indexOf(script)),
      );

      expect(tag).not.toContain('defer');
      expect(tag).not.toContain('async');
    }
  });

  it('still does not pull in the Polaris React package (V1)', () => {
    expect(INDEX_HTML).not.toContain('@shopify/polaris');
  });
});

describe('detecting the components', () => {
  /**
   * jsdom registers no custom elements, which is precisely why the suite could
   * not catch the original fault: an unregistered s-page renders its children
   * here exactly as a registered one would. The guard is the only thing that
   * knows the difference, and only in a browser.
   */
  it('reports the components as missing when nothing has registered them', () => {
    expect(polarisRegistered()).toBe(false);
  });

  it('gives up rather than hanging when the script never arrives', async () => {
    await expect(waitForPolaris({ timeout: 10 })).resolves.toBe(false);
  });

  it('reports them as present once s-page is defined', async () => {
    // Registration is global and permanent, so this is the last assertion in
    // the file that can observe the unregistered state.
    customElements.define('s-page', class extends HTMLElement {});

    expect(polarisRegistered()).toBe(true);
    await expect(waitForPolaris({ timeout: 10 })).resolves.toBe(true);
  });
});

describe('the failure banner', () => {
  function render(options) {
    const container = document.createElement('div');

    document.body.appendChild(container);
    renderRegistrationFailure(container, options);

    return container;
  }

  it('names the cause and the script to check', () => {
    const container = render({ scriptLoaded: true });

    expect(container.textContent).toContain('The Polaris web components did not load');
    expect(container.textContent).toContain(POLARIS_SCRIPT);
    expect(container.textContent).toContain('missing script, not a styling fault');
  });

  /**
   * The symptom that made this hard to place: the admin navigation looks right
   * while the page does not, because the two come from different scripts.
   */
  it('explains the working navigation when App Bridge did load', () => {
    expect(render({ scriptLoaded: true }).textContent).toContain(
      'App Bridge itself did load',
    );
  });

  it('points at the api key as well when App Bridge did not load either', () => {
    expect(render({ scriptLoaded: false }).textContent).toContain('shopify-api-key');
  });

  /**
   * The one thing known to be broken here is s-* elements, so a banner built
   * from them would be more of the same text soup it is reporting.
   */
  it('is built from plain HTML, with no Polaris component anywhere in it', () => {
    const container = render({ scriptLoaded: true });

    expect(container.querySelector('[data-polaris-missing]')).not.toBeNull();
    expect(container.querySelectorAll('*').length).toBeGreaterThan(0);

    for (const element of container.querySelectorAll('*')) {
      expect(element.tagName.toLowerCase()).not.toMatch(/^(s|ui)-/);
    }
  });

  it('replaces whatever was on screen rather than adding to it', () => {
    const container = document.createElement('div');

    container.innerHTML = '<p>half-rendered text soup</p>';
    renderRegistrationFailure(container, { scriptLoaded: true });

    expect(container.textContent).not.toContain('half-rendered text soup');
  });
});
