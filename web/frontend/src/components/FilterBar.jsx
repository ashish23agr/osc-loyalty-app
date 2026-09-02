import { useCallback, useEffect, useRef, useState } from 'react';

import { useElementEvent } from '../app/useElementEvent.js';

/**
 * The filter controls that sit above a table.
 *
 * Polaris form controls emit real DOM events rather than React synthetic ones,
 * and each of these wraps that in the same way. They live together because the
 * Customers, Audit and Loyalty screens all filter the same way and would
 * otherwise each grow their own copy.
 *
 * `s-grid` rather than an inline `s-stack` at the call sites: a form control
 * fills whatever inline size it is given, so one field in a stack takes the
 * whole row and pushes its siblings onto their own.
 */

/**
 * A search box that reports upward only once the typing stops.
 *
 * It owns what is displayed, and that is the point. Filter state lives in the
 * URL, which updates on the debounce; if React drove the field's value straight
 * from the URL it would write the value back into the element mid-word and move
 * the caret to the end. Holding a draft here means React only ever writes the
 * value the person already typed.
 */
export function SearchFilter({ label, placeholder, value = '', onChange, delay = 300 }) {
  const ref = useRef(null);
  const timer = useRef(null);
  const [draft, setDraft] = useState(value);

  // Follows the value from outside — a back button, or a cleared filter — but
  // not the person's own keystrokes coming back around.
  useEffect(() => {
    setDraft(value);
  }, [value]);

  useElementEvent(
    ref,
    'input',
    useCallback(
      (event) => {
        const next = event.currentTarget.value ?? '';

        setDraft(next);
        clearTimeout(timer.current);
        timer.current = setTimeout(() => onChange(next), delay);
      },
      [onChange, delay],
    ),
  );

  useEffect(() => () => clearTimeout(timer.current), []);

  return (
    <s-search-field
      ref={ref}
      label={label}
      labelAccessibilityVisibility="exclusive"
      placeholder={placeholder}
      value={draft}
    />
  );
}

/** A single-choice filter. The first option is always the "all" one. */
export function SelectFilter({ label, name, value, options, onChange }) {
  const ref = useRef(null);

  useElementEvent(
    ref,
    'change',
    useCallback((event) => onChange(event.currentTarget.value ?? ''), [onChange]),
  );

  return (
    <s-select
      ref={ref}
      label={label}
      labelAccessibilityVisibility="exclusive"
      name={name}
      value={value}
    >
      {options.map(([optionValue, optionLabel]) => (
        <s-option key={optionValue || 'any'} value={optionValue}>
          {optionLabel}
        </s-option>
      ))}
    </s-select>
  );
}

export function DateFilter({ label, name, value, onChange }) {
  const ref = useRef(null);

  useElementEvent(
    ref,
    'change',
    useCallback((event) => onChange(event.currentTarget.value ?? ''), [onChange]),
  );

  return <s-date-field ref={ref} label={label} name={name} value={value} />;
}

/**
 * Wire a table's pagination controls to a page number.
 *
 * Every table in the console pages the same way, and the two event names are
 * easy to get subtly wrong in each copy.
 */
export function useTablePagination(tableRef, page, onPage) {
  useElementEvent(
    tableRef,
    'nextpage',
    useCallback(() => onPage(page + 1), [onPage, page]),
  );

  useElementEvent(
    tableRef,
    'previouspage',
    useCallback(() => onPage(Math.max(1, page - 1)), [onPage, page]),
  );
}
