import {readdirSync, readFileSync} from 'node:fs';
import {dirname, join} from 'node:path';
import {fileURLToPath} from 'node:url';

import {describe, expect, it} from 'vitest';

/**
 * Every event handler in the tile must be one the POS component actually emits.
 *
 * **This is the test that was missing, and it is the only part of the V19 fix
 * that prevents a fifth instance.** Renaming nine handlers fixed 3 Sep 2026;
 * this fixes the next time.
 *
 * On that day the tile wired nine controls to `onPress` and the search field to
 * `onSubmit`. POS emits neither. Preact maps an `onX` prop on a custom element
 * to a listener for event `x`, so a wrong name attaches a listener that never
 * fires — silently, with no error, no warning, and no failing test. The result
 * was a tile in which the search box, the member list, the step controls, the
 * redeem button, both enrol paths, cancel and back were all inert, while 31
 * tests passed.
 *
 * They passed because they tested `src/lib/` and never imported the JSX. So
 * this test does not test behaviour — it tests the **contract**, by reading the
 * handler names out of the source and checking each one against the installed
 * `@shopify/ui-extensions` type declarations for that exact tag.
 *
 * **Why the allowlist is derived and not written down.** A hardcoded list of
 * valid handlers would be a second copy of the platform's contract, and it would
 * drift from the real one exactly as silently as `onPress` did. This reads the
 * `.d.ts` files in the installed package, so upgrading the package re-checks
 * the tile against the new contract for free. That is also why V15 — the
 * version skew between the installed package and the declared `api_version` —
 * matters more than it looks: this test is only as truthful as that package.
 *
 * What it cannot catch: a correct handler name wired to the wrong function, or
 * anything at all about runtime behaviour. Rendering `Modal.jsx` would be needed
 * for that, and the cost of it is written up in V19.
 */
const here = dirname(fileURLToPath(import.meta.url));
const componentTypes = join(
  here,
  '..',
  '..',
  '..',
  'node_modules',
  '@shopify',
  'ui-extensions',
  'build',
  'ts',
  'surfaces',
  'point-of-sale',
  'components',
);
const source = join(here, '..', 'src');

/** Tag name -> the set of `on*` props its JSX interface declares. */
function supportedHandlers() {
  const byTag = new Map();

  for (const file of readdirSync(componentTypes).filter((f) => f.endsWith('.d.ts'))) {
    const text = readFileSync(join(componentTypes, file), 'utf8');
    const tag = text.match(/declare const tagName = '([^']+)'/);

    if (!tag) {
      continue;
    }

    byTag.set(tag[1], new Set(text.match(/\bon[A-Z][A-Za-z]*(?=\??:)/g) ?? []));
  }

  return byTag;
}

/** Strip comments so prose mentioning a tag is not read as markup. */
function withoutComments(text) {
  return text
    .replace(/\{\s*\/\*[\s\S]*?\*\/\s*\}/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '');
}

/**
 * Every `<s-tag ... onX={...}>` in a file, as {tag, handler, file} rows.
 *
 * Scans to the closing `>` at brace depth zero, so an arrow function in an
 * attribute value — `onClick={() => search(term)}` — does not end the tag early.
 */
function handlersUsedIn(file) {
  const text = withoutComments(readFileSync(file, 'utf8'));
  const rows = [];
  const opening = /<(s-[a-z-]+)/g;
  let match;

  while ((match = opening.exec(text)) !== null) {
    let depth = 0;
    let index = match.index + match[0].length;

    for (; index < text.length; index += 1) {
      const char = text[index];

      if (char === '{') {
        depth += 1;
      } else if (char === '}') {
        depth -= 1;
      } else if (char === '>' && depth === 0) {
        break;
      }
    }

    const attributes = text.slice(match.index, index);

    for (const handler of attributes.match(/\bon[A-Z][A-Za-z]*(?==)/g) ?? []) {
      rows.push({tag: match[1], handler, file: file.slice(file.lastIndexOf('src'))});
    }
  }

  return rows;
}

function everyHandlerUsed() {
  return readdirSync(source)
    .filter((f) => f.endsWith('.jsx'))
    .flatMap((f) => handlersUsedIn(join(source, f)));
}

describe('the tile only uses handlers POS components emit', () => {
  const supported = supportedHandlers();
  const used = everyHandlerUsed();

  /**
   * The meta-guard, and it is not decoration.
   *
   * A parser that quietly matched nothing would make every assertion below pass
   * for ever — which is the same failure as the one this file exists to prevent,
   * one level up. So the fixtures are asserted before they are used.
   */
  it('actually parsed both the contract and the source', () => {
    expect(supported.size).toBeGreaterThan(20);
    expect(supported.get('s-button')).toContain('onClick');
    expect(supported.get('s-search-field')).toContain('onInput');

    expect(used.length).toBeGreaterThan(8);
    expect(used.map((row) => row.tag)).toContain('s-button');
  });

  it('uses no handler the component does not declare', () => {
    const unsupported = used.filter(({tag, handler}) => {
      const handlers = supported.get(tag);

      // An unknown tag is not this test's business - it would fail to render
      // long before a handler mattered.
      return handlers !== undefined && !handlers.has(handler);
    });

    expect(
      unsupported.map((row) => `${row.file}: <${row.tag} ${row.handler}=`),
    ).toEqual([]);
  });

  /**
   * The two names that were actually wrong, asserted by name.
   *
   * The general assertion above already covers them. These are here so that a
   * reintroduction fails with the word `onPress` in the output rather than a
   * generic list, because the whole cost of this defect was how long it took to
   * find the word.
   */
  it.each([['onPress'], ['onSubmit']])('never uses %s, which POS does not emit', (handler) => {
    for (const [tag, handlers] of supported) {
      expect(
        handlers.has(handler),
        `${tag} unexpectedly declares ${handler}; the contract has changed and V19 should be revisited`,
      ).toBe(false);
    }

    expect(used.filter((row) => row.handler === handler)).toEqual([]);
  });

  /** The search must be reachable by pressing something. */
  it('gives the lookup screen a real search button', () => {
    const modal = withoutComments(readFileSync(join(source, 'Modal.jsx'), 'utf8'));

    expect(modal).toMatch(/<s-button[^>]*onClick=\{\(\) => search\(term\)\}/);
  });
});
