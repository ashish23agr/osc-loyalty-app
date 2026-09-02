import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';

import { listMembers } from '../api/admin.js';
import RoleGate from '../app/RoleGate.jsx';
import { SearchFilter, SelectFilter, useTablePagination } from '../components/FilterBar.jsx';
import { EmptyState, ErrorBanner } from '../components/states.jsx';
import { formatDate, formatPence, formatPoints, initials, NOT_HELD } from '../lib/format.js';
import { SEGMENT } from '../lib/labels.js';

/**
 * A2 — Customers, search and list.
 *
 * The single search box is the point of the screen. MD1 confirmed that legacy
 * members with no email address migrate and must stay findable, so the query
 * goes across email, name, postcode, the club card number and the legacy
 * Dynamics card number at once, and a member with no email is labelled in red
 * rather than shown as a blank cell that reads like missing data.
 *
 * Filter state lives in the URL. A search someone wants a colleague to look at
 * is then a link, and the browser back button does what it looks like it does.
 */

/** The field list from the agreed UI, in its order and wording. */
const FIELDS = [
  ['all', 'Search: all fields'],
  ['email', 'Email — primary identifier'],
  ['name', 'Name'],
  ['postcode', 'Postcode'],
  ['card', 'Card number (new)'],
  ['legacy_card', 'Legacy card number'],
];

const SEGMENTS = [
  ['', 'Segment: all'],
  ['active', 'Active'],
  ['lapsed', 'Lapsed'],
  // Not in the agreed UI, and deliberately added: `unknown` is the schema
  // default, so most members hold it and leaving it out would make them
  // unreachable by this filter.
  ['unknown', 'Unknown'],
];

const CHANNELS = [
  ['', 'Enrolled: any channel'],
  ['online', 'Online'],
  ['pos', 'In-store'],
  ['migration', 'Migrated'],
  // Console enrolment exists in the data and is not in the agreed UI list.
  ['admin', 'Console'],
];

const PER_PAGE = 25;

export default function CustomersScreen() {
  const [params, setParams] = useSearchParams();
  const navigate = useNavigate();

  const query = params.get('q') ?? '';
  const field = params.get('field') ?? 'all';
  const segment = params.get('segment') ?? '';
  const channel = params.get('channel') ?? '';
  const page = Number(params.get('page') ?? 1);

  const [result, setResult] = useState({ status: 'loading' });

  useEffect(() => {
    let cancelled = false;

    setResult((current) => ({ ...current, status: 'loading' }));

    listMembers({ q: query, field, segment, channel, page, perPage: PER_PAGE })
      .then((data) => {
        if (!cancelled) {
          setResult({ status: 'ready', data });
        }
      })
      .catch((error) => {
        if (!cancelled) {
          setResult({ status: 'error', error });
        }
      });

    return () => {
      cancelled = true;
    };
  }, [query, field, segment, channel, page]);

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

        // Any change to what is being asked for starts again at page one.
        if (!('page' in changes)) {
          next.delete('page');
        }

        return next;
      });
    },
    [setParams],
  );

  const members = result.data?.data ?? [];
  const meta = result.data?.meta ?? {};
  const total = meta.total ?? 0;

  return (
    <s-page heading="Customers">
      <s-section>
        <s-stack direction="inline" gap="base" alignItems="center">
          <s-text color="subdued">
            {result.status === 'ready' ? `${formatPoints(total)} members` : 'Counting members…'}
          </s-text>
          <s-button disabled>Export CSV</s-button>
          {/* Enrolment is an Agent action and a Viewer has no use for it, so it
              is hidden rather than greyed out. For a role that does hold it, the
              button is shown disabled: the modal is the next piece of work, and
              leaving it off the agreed layout would hide that it is coming. */}
          <RoleGate permission="enrol_members">
            <s-button variant="primary" disabled>
              Enrol member
            </s-button>
          </RoleGate>
        </s-stack>
      </s-section>

      <s-section>
        <ErrorBanner error={result.error} heading="The customer list could not be loaded" />

        <MemberTable
          members={members}
          meta={meta}
          loading={result.status === 'loading'}
          page={page}
          query={query}
          onSearch={(value) => update({ q: value })}
          field={field}
          segment={segment}
          channel={channel}
          onFilter={update}
          onOpen={(id) => navigate(`/customers/${id}`)}
          onPage={(next) => update({ page: next })}
        />

        {result.status === 'ready' && total === 0 ? (
          <EmptyState heading="No members match that search">
            Try a card number, a postcode, or a surname. A member with no email address is found the
            same way.
          </EmptyState>
        ) : null}

        <s-paragraph tone="neutral">
          Email is the primary customer identifier. Postcode and name are supporting fields for
          lookup and duplicate validation.
        </s-paragraph>
      </s-section>
    </s-page>
  );
}

function MemberTable({
  members,
  meta,
  loading,
  page,
  query,
  onSearch,
  field,
  segment,
  channel,
  onFilter,
  onOpen,
  onPage,
}) {
  const tableRef = useRef(null);
  const lastPage = meta.last_page ?? 1;

  useTablePagination(tableRef, page, onPage);

  return (
    <s-table
      ref={tableRef}
      variant="auto"
      paginate
      loading={loading || undefined}
      hasPreviousPage={page > 1 || undefined}
      hasNextPage={page < lastPage || undefined}
    >
      <s-grid slot="filters" gridTemplateColumns="2fr 1fr 1fr 1fr" gap="base" alignItems="end">
        <SearchFilter
          label="Search members"
          placeholder="Search members, cards, postcodes…"
          value={query}
          onChange={onSearch}
        />
        <SelectFilter
          label="Search field"
          name="field"
          value={field}
          options={FIELDS}
          onChange={(value) => onFilter({ field: value === 'all' ? '' : value })}
        />
        <SelectFilter
          label="Segment"
          name="segment"
          value={segment}
          options={SEGMENTS}
          onChange={(value) => onFilter({ segment: value })}
        />
        <SelectFilter
          label="Enrolment channel"
          name="channel"
          value={channel}
          options={CHANNELS}
          onChange={(value) => onFilter({ channel: value })}
        />
      </s-grid>

      <s-table-header-row>
        <s-table-header listSlot="primary">Member</s-table-header>
        <s-table-header>Email — primary ID</s-table-header>
        <s-table-header>Postcode</s-table-header>
        <s-table-header>Card no.</s-table-header>
        <s-table-header>Legacy card</s-table-header>
        <s-table-header listSlot="labeled">Available</s-table-header>
        <s-table-header listSlot="labeled">Pending</s-table-header>
        <s-table-header listSlot="labeled">Voucher value</s-table-header>
        <s-table-header listSlot="inline">Segment</s-table-header>
        <s-table-header />
      </s-table-header-row>

      <s-table-body>
        {members.map((member) => (
          <MemberRow key={member.id} member={member} onOpen={onOpen} />
        ))}
      </s-table-body>
    </s-table>
  );
}

function MemberRow({ member, onOpen }) {
  const segment = SEGMENT[member.segment] ?? SEGMENT.unknown;

  return (
    <s-table-row>
      <s-table-cell>
        <s-stack direction="inline" gap="small-100" alignItems="center">
          <s-avatar initials={initials(member.first_name, member.last_name)} size="small" />
          <s-stack direction="block">
            <s-text type="strong">{member.display_name ?? 'No name held'}</s-text>
            <s-text color="subdued">joined {formatDate(member.enrolled_at)}</s-text>
          </s-stack>
        </s-stack>
      </s-table-cell>

      <s-table-cell>
        {/* MD1: a supported state, and the agreed UI marks it in red. A blank
            cell here would read as data that failed to load. */}
        {member.has_no_email ? (
          <s-badge tone="critical">No email held</s-badge>
        ) : (
          <s-text>{member.email}</s-text>
        )}
      </s-table-cell>

      <s-table-cell>{member.postcode ?? NOT_HELD}</s-table-cell>
      <s-table-cell>{member.card_number}</s-table-cell>
      <s-table-cell>{member.legacy_card_number ?? NOT_HELD}</s-table-cell>
      <s-table-cell>{formatPoints(member.points_available)}</s-table-cell>
      <s-table-cell>{formatPoints(member.points_pending)}</s-table-cell>
      <s-table-cell>{formatPence(member.voucher_balance_pence)}</s-table-cell>

      <s-table-cell>
        <s-badge tone={segment.tone}>{segment.label}</s-badge>
      </s-table-cell>

      <s-table-cell>
        <s-button
          variant="secondary"
          onClick={() => onOpen(member.id)}
          accessibilityLabel={`Open ${member.display_name ?? 'member'}`}
        >
          Open
        </s-button>
      </s-table-cell>
    </s-table-row>
  );
}
