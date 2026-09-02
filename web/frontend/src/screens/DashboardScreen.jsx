import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';

import { getOverview } from '../api/admin.js';
import { EmptyState, ErrorBanner, Loading, NotHeld } from '../components/states.jsx';
import {
  formatChange,
  formatDate,
  formatDateTimeLong,
  formatDelta,
  formatPence,
  formatPoints,
  NOT_HELD,
} from '../lib/format.js';
import { CHANNEL, ENTRY_TYPE, lookup } from '../lib/labels.js';

/**
 * A1 — Dashboard.
 *
 * Programme health at a glance, in one request. Six cards and the recent
 * activity strip, all from `GET /api/admin/overview`.
 *
 * Two of the sub-figures in the agreed UI are not figures this system holds, and
 * neither is invented:
 *
 *   **Redemption rate.** Redemption arrives in Sprint 3, and until it does there
 *   is no denominator to divide by. The card shows an em dash with the amount
 *   actually redeemed underneath, which is a real query against
 *   `loyalty_redemptions` and will be zero until the till and the checkout start
 *   writing to it.
 *
 *   **"N vouchers unredeemed."** Per D1 nothing is issued: a member's reward
 *   value is derived from their available points on read, rises and falls with
 *   them, and has no expiry of its own. There is no count of vouchers to give,
 *   so the card says where the figure comes from rather than inventing an
 *   object that does not exist.
 */
export default function DashboardScreen() {
  const [result, setResult] = useState({ status: 'loading' });

  useEffect(() => {
    let cancelled = false;

    getOverview()
      .then((data) => !cancelled && setResult({ status: 'ready', data }))
      .catch((error) => !cancelled && setResult({ status: 'error', error }));

    return () => {
      cancelled = true;
    };
  }, []);

  if (result.status === 'loading') {
    return (
      <s-page heading="Dashboard">
        <s-section>
          <Loading label="Loading the programme figures…" />
        </s-section>
      </s-page>
    );
  }

  if (result.status === 'error') {
    return (
      <s-page heading="Dashboard">
        <s-section>
          <ErrorBanner error={result.error} heading="The dashboard could not be loaded" />
        </s-section>
      </s-page>
    );
  }

  const overview = result.data;

  return (
    <s-page heading="Dashboard">
      <s-section>
        <s-stack direction="inline" gap="base" alignItems="center">
          <s-text color="subdued">
            Live figures · {formatDateTimeLong(overview.generated_at)}
          </s-text>

          {/* The agreed UI offers three ranges. The endpoint answers fixed
              windows — thirty days, and today in the reporting timezone — so the
              control is shown inert rather than offering a choice that would do
              nothing. Selectable ranges arrive with Reports in Sprint 5. */}
          <s-select
            label="Date range"
            labelAccessibilityVisibility="exclusive"
            name="range"
            value="30d"
            disabled
          >
            <s-option value="30d">Last 30 days</s-option>
          </s-select>

          <s-button disabled>Export summary</s-button>
        </s-stack>

        <s-paragraph tone="neutral">
          Figures cover the last 30 days. Other date ranges and the summary export
          arrive with Reports in Sprint 5.
        </s-paragraph>
      </s-section>

      <SummaryCards overview={overview} />
      <RecentActivity entries={overview.recent_activity ?? []} />
    </s-page>
  );
}

function SummaryCards({ overview }) {
  const { members, enrolments, points, redemptions, jobs } = overview;

  return (
    <s-section>
      <s-grid gridTemplateColumns="1fr 1fr 1fr" gap="base">
        <Card
          label="Total members"
          value={formatPoints(members.total)}
          note={`${formatPoints(members.segment_active)} active · ${formatPoints(
            members.segment_lapsed,
          )} lapsed`}
        />

        <Card
          label="New sign-ups (30d)"
          value={formatPoints(enrolments.last_30_days)}
          note={
            <s-stack direction="inline" gap="small-500" alignItems="center">
              {/* Null rather than zero when there is no previous month to
                  compare against: a first month is not a flat month. */}
              <s-text type="strong">{formatChange(enrolments.change_percent)}</s-text>
              <s-text color="subdued">
                · {formatPoints(enrolments.by_channel.online)} online /{' '}
                {formatPoints(enrolments.by_channel.pos)} in-store
              </s-text>
            </s-stack>
          }
        />

        <Card
          label="Points issued (30d)"
          value={formatPoints(points.points_issued_last_30_days)}
          note="All qualifying items"
        />

        <Card
          label="Pending points"
          value={formatPoints(points.points_pending)}
          note={
            jobs.oldest_pending_clears_at
              ? `Oldest clears ${formatDate(jobs.oldest_pending_clears_at)}`
              : 'Nothing pending'
          }
        />

        <Card
          label="Voucher value outstanding"
          value={formatPence(points.voucher_value_outstanding_pence)}
          // D1: nothing is issued, so there is no count of vouchers to quote.
          note="Derived from available points — nothing is issued (D1)"
        />

        <Card
          label="Redemption rate (30d)"
          // Sprint 3 brings redemption; until then there is no rate to state.
          value={<NotHeld label="Redemption rate" />}
          note={`${formatPence(redemptions.last_30_days_pence)} redeemed · rate arrives with redemption in Sprint 3`}
        />
      </s-grid>
    </s-section>
  );
}

function RecentActivity({ entries }) {
  return (
    <s-section heading="Recent loyalty activity">
      <s-stack direction="inline" gap="base" alignItems="center">
        {/* The agreed UI links this to Transactions (A7), which Sprint 2 owns.
            Until it exists the link goes to the programme ledger, which is the
            same movements seen through the screen that is built. */}
        <Link to="/loyalty">
          <s-text>View the programme ledger</s-text>
        </Link>
      </s-stack>

      <s-table variant="auto">
        <s-table-header-row>
          <s-table-header listSlot="primary">Member</s-table-header>
          <s-table-header>Action</s-table-header>
          <s-table-header>Channel</s-table-header>
          <s-table-header listSlot="labeled">Points</s-table-header>
          <s-table-header>Reference</s-table-header>
          <s-table-header>Time</s-table-header>
        </s-table-header-row>

        <s-table-body>
          {entries.map((entry) => (
            <s-table-row key={entry.id}>
              <s-table-cell>
                <Link to={`/customers/${entry.member.id}`}>
                  <s-text>{entry.member.display_name ?? entry.member.card_number}</s-text>
                </Link>
              </s-table-cell>
              <s-table-cell>
                <s-stack direction="inline" gap="small-500" alignItems="center">
                  <s-text>{lookup(ENTRY_TYPE, entry.entry_type, entry.entry_type)}</s-text>
                  {entry.status === 'pending' ? <s-badge tone="warning">Pending</s-badge> : null}
                </s-stack>
              </s-table-cell>
              <s-table-cell>{lookup(CHANNEL, entry.channel, entry.channel)}</s-table-cell>
              <s-table-cell>{formatDelta(entry.points)}</s-table-cell>
              <s-table-cell>{entry.reference ?? NOT_HELD}</s-table-cell>
              <s-table-cell>{timeOf(entry.occurred_at)}</s-table-cell>
            </s-table-row>
          ))}
        </s-table-body>
      </s-table>

      {entries.length === 0 ? (
        <EmptyState heading="Nothing has happened yet">
          Earning, redemptions and adjustments appear here as they are posted.
        </EmptyState>
      ) : null}
    </s-section>
  );
}

/** The activity strip is today's, so the clock time is all it needs. */
function timeOf(iso) {
  const date = iso ? new Date(iso) : null;

  if (date === null || Number.isNaN(date.getTime())) {
    return NOT_HELD;
  }

  return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

function Card({ label, value, note }) {
  return (
    <s-box padding="base" border="base" borderRadius="base">
      <s-stack direction="block" gap="small-500">
        <s-text color="subdued">{label}</s-text>
        <s-heading>{value}</s-heading>
        {note ? <s-text color="subdued">{note}</s-text> : null}
      </s-stack>
    </s-box>
  );
}
