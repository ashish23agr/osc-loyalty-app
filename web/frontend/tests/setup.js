import '@testing-library/jest-dom/vitest';

import { cleanup } from '@testing-library/react';
import { afterEach, beforeEach, expect, vi } from 'vitest';

/**
 * Test setup for the console.
 *
 * The Polaris `s-*` elements are registered by the App Bridge script at
 * runtime, which does not load here. jsdom treats an unregistered custom
 * element as an ordinary unknown element, so the tree still renders and its
 * text is still queryable — which is what these tests assert on. What they
 * cannot assert is how a component looks, so nothing here tries to.
 *
 * A console error or warning fails the test. Every screen is required to load
 * clean, and a React warning about a bad prop or a missing key is exactly the
 * kind of defect that otherwise survives to review.
 */
let consoleError;
let consoleWarn;

beforeEach(() => {
  consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
  consoleWarn = vi.spyOn(console, 'warn').mockImplementation(() => {});
});

afterEach(() => {
  const complaints = [
    ...consoleError.mock.calls.map((args) => ['error', args]),
    ...consoleWarn.mock.calls.map((args) => ['warn', args]),
  ];

  consoleError.mockRestore();
  consoleWarn.mockRestore();
  cleanup();

  expect(
    complaints.map(([level, args]) => `console.${level}: ${args.map(String).join(' ')}`),
  ).toEqual([]);
});
