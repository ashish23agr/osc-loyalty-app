import { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

import { listAudit } from '../api/admin.js';
import {
  DateFilter,
  SearchFilter,
  SelectFilter,
  useTablePagination,
} from '../components/FilterBar.jsx';
import { EmptyState, ErrorBanner } from '../components/states.jsx';
import { formatDateTime, NOT_HELD } from '../lib/format.js';
import { ACTOR_TYPE, AUDIT_ACTION, ROLE, lookup } from '../lib/labels.js';
import { formatCardNumber } from '../lib/cardNumber.js';

/**
 * A12 — Audit log.
 *
 * Read-only, and there is no companion write endpoint anywhere in the
 * application: entries are created in process, inside the same transaction as
 * the effect they describe, and the model refuses updates and deletes. The
 * footer says so, because a log nobody believes is immutable is not much use in
 * a dispute.
 *
 * Manager floor. A role below it never sees the nav item, and reaching the
 * address directly gets the server's own refusal rather than an empty table
 * that looks like nothing ever happened.
 */
const PER_PAGE = 25;

export default function AuditScreen() {
  const [params, setParams] = useSearchParams();

  const query = params.get('q') ?? '';
  const action = params.get('action') ?? '';
  const actor = params.get('actor') ?? '';
  const from = params.get('from') ?? '';
  const to = params.get('to') ?? '';
  const page = Number(params.get('page') ?? 1);

  const [result, setResult] = useState({ status: 'loading' });
  const tableRef = useRef(null);

  useEffect(() => {
    let cancelled = false;
    setResult((current) => ({ ...current, status: 'loading' }));

    listAudit({
      q: query,
      action: action || undefined,
      actorStaffId: actor || undefined,
      from: from || undefined,
      to: to || undefined,
      page,
      perPage: PER_PAGE,
    })
      .then((data) => !cancelled && setResult({ status: 'ready', data }))
      .catch((error) => !cancelled && setResult({ status: 'error', error }));

    return () => {
      cancelled = true;
    };
  }, [query, action, actor, from, to, page]);

  const update = useCallback(
    (changes) => {
      setParams((current) => {
        const next = new URLSearchParams(current);

        for (const [key, value] of Object.entries(changes)) {
          if (value === '' || value === null || value === undefined) {
            next.delete(key);
          } else {
            next.set(key, String(value));
          }
        }

        if (!('page' in changes)) {
          next.delete('page');
        }

        return next;
      });
    },
    [setParams],
  );

  useTablePagination(
    tableRef,
    page,
    useCallback((next) => update({ page: next }), [update]),
  );

  const entries = result.data?.data ?? [];
  const meta = result.data?.meta ?? {};
  const filters = meta.filters ?? {};

  // Built from what has actually happened on this shop, so someone whose role
  // was later revoked stays selectable — which is exactly when they matter.
  const actorOptions = [
    ['', 'User: all'],
    ...(filters.actors ?? []).map((one) => [String(one.staff_id), one.name ?? `Staff ${one.staff_id}`]),
  ];

  const actionOptions = [
    ['', 'Action: all'],
    ...(filters.actions ?? []).map((one) => [one, lookup(AUDIT_ACTION, one, one)]),
  ];

  if (result.status === 'error' && result.error?.isForbidden) {
    return <Refused error={result.error} />;
  }

  return (
    <s-page heading="Audit log">
      <s-section>
        <s-stack direction="inline" gap="base" alignItems="center">
          <s-text color="subdued">Immutable · retained for the life of the programme</s-text>
          <s-button disabled>Export CSV</s-button>
        </s-stack>
      </s-section>

      <s-section>
        <ErrorBanner error={result.error} heading="The audit log could not be loaded" />

        <s-table
          ref={tableRef}
          variant="auto"
          paginate
          loading={result.status === 'loading' || undefined}
          hasPreviousPage={page > 1 || undefined}
          hasNextPage={page < (meta.last_page ?? 1) || undefined}
        >
          <s-grid
            slot="filters"
            gridTemplateColumns="2fr 1fr 1fr 1fr 1fr"
            gap="base"
            alignItems="end"
          >
            <SearchFilter
              label="Search the audit log"
              placeholder="Search user, member, reference…"
              value={query}
              onChange={(value) => update({ q: value })}
            />
            <SelectFilter
              label="Action"
              name="action"
              value={action}
              options={actionOptions}
              onChange={(value) => update({ action: value })}
            />
            <SelectFilter
              label="User"
              name="actor"
              value={actor}
              options={actorOptions}
              onChange={(value) => update({ actor: value })}
            />
            <DateFilter
              label="From"
              name="from"
              value={from}
              onChange={(value) => update({ from: value })}
            />
            <DateFilter label="To" name="to" value={to} onChange={(value) => update({ to: value })} />
          </s-grid>

          <s-table-header-row>
            <s-table-header listSlot="primary">Timestamp</s-table-header>
            <s-table-header>User</s-table-header>
            <s-table-header>Role</s-table-header>
            <s-table-header>Action</s-table-header>
            <s-table-header>Target</s-table-header>
            <s-table-header>Before → after</s-table-header>
            <s-table-header>Reason</s-table-header>
          </s-table-header-row>

          <s-table-body>
            {entries.map((entry) => (
              <AuditRow key={entry.id} entry={entry} />
            ))}
          </s-table-body>
        </s-table>

        {result.status === 'ready' && entries.length === 0 ? (
          <EmptyState heading="Nothing matches those filters">
            Widen the date range, or clear the action and user filters.
          </EmptyState>
        ) : null}

        <s-paragraph tone="neutral">
          Entries cannot be edited or deleted from the interface.
        </s-paragraph>
      </s-section>
    </s-page>
  );
}

function AuditRow({ entry }) {
  return (
    <s-table-row>
      <s-table-cell>{formatDateTime(entry.created_at)}</s-table-cell>
      <s-table-cell>{entry.actor.name ?? lookup(ACTOR_TYPE, entry.actor.type, NOT_HELD)}</s-table-cell>
      <s-table-cell>
        {/* The system and the migration importer hold no role, and the agreed
            UI writes that as an em dash rather than inventing one. */}
        {entry.actor.role ? lookup(ROLE, entry.actor.role, entry.actor.role) : NOT_HELD}
      </s-table-cell>
      <s-table-cell>{lookup(AUDIT_ACTION, entry.action, entry.action)}</s-table-cell>
      <s-table-cell>{target(entry)}</s-table-cell>
      <s-table-cell>
        <Changes entry={entry} />
      </s-table-cell>
      <s-table-cell>{entry.reason ?? NOT_HELD}</s-table-cell>
    </s-table-row>
  );
}

/**
 * What the action was done to, named the way a person would name it.
 *
 * A member is their card number, because that is what the agreed UI shows and
 * what someone holding a card can read back. Everything else is its type and id.
 */
function target(entry) {
  if (entry.subject_id === null) {
    return entry.subject_type;
  }

  if (entry.subject_type === 'LoyaltyAccount') {
    return formatCardNumber(entry.subject_id);
  }

  return `${entry.subject_type} ${entry.subject_id}`;
}

/**
 * The before-to-after column.
 *
 * A field that moved is shown as "340 → 390". A field that only exists on one
 * side — an enrolment has no before, a rule save records the new version number
 * — is shown as a plain value, because dropping it would leave the column empty
 * for exactly the actions that create things.
 */
function Changes({ entry }) {
  const moved = (entry.changes ?? []).filter(
    (change) => change.from !== null && change.from !== undefined,
  );

  const added = (entry.changes ?? []).filter(
    (change) => (change.from === null || change.from === undefined) && change.to !== null,
  );

  if (moved.length === 0 && added.length === 0) {
    return NOT_HELD;
  }

  return (
    <s-stack direction="block" gap="small-500">
      {moved.map((change) => (
        <s-text key={change.field}>
          {label(change.field)}: {display(change.from)} → {display(change.to)}
        </s-text>
      ))}
      {added.map((change) => (
        <s-text key={change.field} color="subdued">
          {label(change.field)}: {display(change.to)}
        </s-text>
      ))}
    </s-stack>
  );
}

function label(field) {
  return String(field).replaceAll('_', ' ');
}

function display(value) {
  if (value === null || value === undefined) {
    return NOT_HELD;
  }

  if (typeof value === 'boolean') {
    return value ? 'yes' : 'no';
  }

  if (typeof value === 'object') {
    return JSON.stringify(value);
  }

  return String(value);
}

/**
 * The server's refusal, shown as a screen.
 *
 * Reaching this address without the Manager role is a legitimate thing to do —
 * a bookmark, a shared link — and it should read as "not for you", not as a
 * crash.
 */
function Refused({ error }) {
  return (
    <s-page heading="Audit log">
      <s-section>
        <s-banner heading="You cannot see the audit log" tone="warning">
          <s-paragraph>{error.message}</s-paragraph>
          {error.details?.required ? (
            <s-paragraph>
              It needs the {lookup(ROLE, error.details.required, error.details.required)} role; you
              hold {lookup(ROLE, error.details.held, error.details.held)}.
            </s-paragraph>
          ) : null}
        </s-banner>
      </s-section>
    </s-page>
  );
}
