import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';

import { getOverview, getRules, listProgrammeLedger } from '../api/admin.js';
import { SelectFilter, useTablePagination } from '../components/FilterBar.jsx';
import { EmptyState, ErrorBanner, NotHeld } from '../components/states.jsx';
import { formatDate, formatDateTime, formatDelta, formatPence, formatPoints, NOT_HELD } from '../lib/format.js';
import { CHANNEL, ENTRY_TYPE, lookup } from '../lib/labels.js';

/**
 * A5 — Loyalty, the programme ledger and what the scheduled jobs owe.
 *
 * The engine's own view. Three requests: the ledger (which carries its own
 * summary tiles), the overview (for job health, which is counted from the ledger
 * rather than from a job table, so a queue that stopped shows as work piling up),
 * and the rules in force.
 *
 * All three sub-figures the agreed UI asks for are now real, because Sprint 2's
 * engine produces them: the qualifying spend behind today's earning (held on
 * every earn since Sprint 1, but nothing posted one until now), the next expiry
 * run (read from the schedule itself rather than restated here), and the refund
 * and cancellation split behind today's reversals (a reversal driven by a refund
 * carries a refund id; one driven by a cancellation does not).
 *
 * The em-dash rule still stands for everything a later sprint owns. Nothing here
 * invents a figure the programme does not hold.
 */
const PER_PAGE = 25;

const TYPES = [
  ['', 'Type: all'],
  ['earn', 'Earn'],
  ['maturity', 'Maturity'],
  ['redemption', 'Redemption'],
  ['earn_reversal', 'Reversal'],
  ['expiry', 'Expiry'],
  ['adjustment', 'Adjustment'],
  ['opening_balance', 'Opening balance'],
  ['redemption_restore', 'Redemption restored'],
];

const CHANNELS = [
  ['', 'Channel: all'],
  ['online', 'Online'],
  ['pos', 'In-store'],
  ['admin', 'Console'],
  ['system', 'Automatic'],
  ['migration', 'Migrated'],
];

export default function LoyaltyScreen() {
  const [params, setParams] = useSearchParams();

  const type = params.get('type') ?? '';
  const channel = params.get('channel') ?? '';
  const page = Number(params.get('page') ?? 1);

  const [ledger, setLedger] = useState({ status: 'loading' });
  const [overview, setOverview] = useState({ status: 'loading' });
  const [rules, setRules] = useState({ status: 'loading' });
  const tableRef = useRef(null);

  useEffect(() => {
    let cancelled = false;
    setLedger((current) => ({ ...current, status: 'loading' }));

    listProgrammeLedger({
      type: type || undefined,
      channel: channel || undefined,
      page,
      perPage: PER_PAGE,
    })
      .then((data) => !cancelled && setLedger({ status: 'ready', data }))
      .catch((error) => !cancelled && setLedger({ status: 'error', error }));

    return () => {
      cancelled = true;
    };
  }, [type, channel, page]);

  // Job health and the rules do not change with the filters, so they are read
  // once rather than on every page turn.
  useEffect(() => {
    let cancelled = false;

    getOverview()
      .then((data) => !cancelled && setOverview({ status: 'ready', data }))
      .catch((error) => !cancelled && setOverview({ status: 'error', error }));

    getRules()
      .then((data) => !cancelled && setRules({ status: 'ready', data }))
      .catch((error) => !cancelled && setRules({ status: 'error', error }));

    return () => {
      cancelled = true;
    };
  }, []);

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

  const entries = ledger.data?.data ?? [];
  const meta = ledger.data?.meta ?? {};

  return (
    <s-page heading="Loyalty">
      <s-section>
        <s-stack direction="inline" gap="base" alignItems="center">
          <s-text color="subdued">Points ledger, earning rules in force, expiry schedule</s-text>
          <s-button disabled>Export ledger</s-button>
          <Link to="/settings">
            <s-button>Rules settings</s-button>
          </Link>
        </s-stack>
      </s-section>

      <SummaryTiles summary={meta.summary} overview={overview.data} />

      <s-section heading="Programme ledger">
        <ErrorBanner error={ledger.error} heading="The programme ledger could not be loaded" />

        <s-table
          ref={tableRef}
          variant="auto"
          paginate
          loading={ledger.status === 'loading' || undefined}
          hasPreviousPage={page > 1 || undefined}
          hasNextPage={page < (meta.last_page ?? 1) || undefined}
        >
          <s-grid slot="filters" gridTemplateColumns="1fr 1fr 2fr" gap="base" alignItems="end">
            <SelectFilter
              label="Entry type"
              name="type"
              value={type}
              options={TYPES}
              onChange={(value) => update({ type: value })}
            />
            <SelectFilter
              label="Channel"
              name="channel"
              value={channel}
              options={CHANNELS}
              onChange={(value) => update({ channel: value })}
            />
            <s-text color="subdued">
              {ledger.status === 'ready' ? `${formatPoints(meta.total)} movements` : ''}
            </s-text>
          </s-grid>

          <s-table-header-row>
            <s-table-header listSlot="primary">Time</s-table-header>
            <s-table-header>Member</s-table-header>
            <s-table-header>Type</s-table-header>
            <s-table-header>Channel</s-table-header>
            <s-table-header listSlot="labeled">Delta</s-table-header>
          </s-table-header-row>

          <s-table-body>
            {entries.map((entry) => (
              <s-table-row key={entry.id}>
                <s-table-cell>{formatDateTime(entry.occurred_at)}</s-table-cell>
                <s-table-cell>
                  <Link to={`/customers/${entry.member.id}`}>
                    <s-text>{entry.member.display_name ?? entry.member.card_number}</s-text>
                  </Link>
                </s-table-cell>
                <s-table-cell>{lookup(ENTRY_TYPE, entry.entry_type, entry.entry_type)}</s-table-cell>
                <s-table-cell>{lookup(CHANNEL, entry.channel, entry.channel)}</s-table-cell>
                <s-table-cell>{formatDelta(entry.points)}</s-table-cell>
              </s-table-row>
            ))}
          </s-table-body>
        </s-table>

        {ledger.status === 'ready' && entries.length === 0 ? (
          <EmptyState heading="No movements match those filters">
            Clear the type and channel filters to see the whole programme.
          </EmptyState>
        ) : null}
      </s-section>

      <RulesInForce rules={rules} />
    </s-page>
  );
}

function SummaryTiles({ summary, overview }) {
  if (!summary) {
    return (
      <s-section>
        <s-stack direction="inline" gap="base" alignItems="center">
          <s-spinner size="base" accessibilityLabel="Loading the programme figures" />
          <s-text>Loading the programme figures…</s-text>
        </s-stack>
      </s-section>
    );
  }

  const jobs = overview?.jobs;
  const window = summary.expiring_soon;

  return (
    <s-section>
      <s-grid gridTemplateColumns="1fr 1fr 1fr 1fr 1fr" gap="base">
        <Tile
          label="Points in circulation"
          value={formatPoints(summary.points_in_circulation)}
          note={`${formatPence(summary.voucher_value_outstanding_pence)} of voucher value`}
        />

        <Tile
          label="Earned today"
          value={formatPoints(summary.points_earned_today)}
          note={
            summary.points_earned_today > 0
              ? `${formatPence(summary.qualifying_spend_today_pence)} of qualifying spend`
              : 'Nothing earned today'
          }
        />

        <Tile
          label="Pending (not yet available)"
          value={formatPoints(summary.points_pending)}
          note={
            jobs?.oldest_pending_clears_at
              ? `Oldest clears ${formatDate(jobs.oldest_pending_clears_at)}`
              : 'Nothing pending'
          }
        />

        <Tile
          label={`Expiring in ${window.window_days} days`}
          value={formatPoints(window.points)}
          note={
            jobs?.next_expiry_run_at
              ? `Next run ${formatDateTime(jobs.next_expiry_run_at)}`
              : <NotHeld label="Next expiry run" />
          }
        />

        <Tile
          label="Reversals today"
          value={formatDelta(summary.points_reversed_today)}
          note={reversalSplit(summary.reversals_today)}
        />
      </s-grid>

      {jobs ? <JobHealth jobs={jobs} /> : null}
    </s-section>
  );
}

/**
 * The refund and cancellation split behind today's reversals.
 *
 * Says "no reversals today" rather than "0 refunds, 0 cancellations", because
 * the second reads like a fault on a quiet day.
 */
function reversalSplit(split) {
  if (!split) {
    return <NotHeld label="Reversal breakdown" />;
  }

  const refunds = split.refunds?.entries ?? 0;
  const cancellations = split.cancellations?.entries ?? 0;

  if (refunds === 0 && cancellations === 0) {
    return 'No reversals today';
  }

  return `${formatPoints(refunds)} from refunds \u00b7 ${formatPoints(cancellations)} from cancellations`;
}

/**
 * What the scheduled work owes.
 *
 * Counted from the ledger, so a queue that silently stopped shows here as
 * points that should have matured and have not — which is the failure this
 * programme is most exposed to and the one with no other visible symptom.
 */
function JobHealth({ jobs }) {
  const behind = jobs.overdue_maturities.entries > 0 || jobs.overdue_expiries.lots > 0;

  if (!behind) {
    return (
      <s-stack direction="block" gap="small-500">
        <s-paragraph tone="neutral">
          Nothing is waiting: every matured earning and every expired lot has been processed.
        </s-paragraph>
        <LastRuns runs={jobs.last_runs} />
      </s-stack>
    );
  }

  return (
    <s-banner heading="Scheduled work is behind" tone="warning">
      <s-paragraph>
        {formatPoints(jobs.overdue_maturities.points)} points in{' '}
        {formatPoints(jobs.overdue_maturities.entries)} earnings have passed their maturity date and
        are still pending, and {formatPoints(jobs.overdue_expiries.points)} points in{' '}
        {formatPoints(jobs.overdue_expiries.lots)} lots are past their expiry date. A queue worker
        and the scheduler must be running.
      </s-paragraph>
      <LastRuns runs={jobs.last_runs} />
    </s-banner>
  );
}

/**
 * When each sweep last completed, from the audit log.
 *
 * Only possible because every scheduled run writes one audit entry carrying its
 * counts (C12). A sweep that silently stopped shows here as a date that stopped
 * moving, which is the failure this programme is most exposed to and the one
 * with no other visible symptom.
 */
function LastRuns({ runs }) {
  const jobs = Object.entries(runs ?? {});

  if (jobs.length === 0) {
    return (
      <s-text color="subdued">
        No scheduled run has been recorded yet. The scheduler may not be running.
      </s-text>
    );
  }

  return (
    <s-stack direction="block" gap="small-300">
      {jobs.map(([name, run]) => (
        <s-text key={name} color="subdued">
          {JOB_LABEL[name] ?? name} last ran {formatDateTime(run.at)}
        </s-text>
      ))}
    </s-stack>
  );
}

/** The scheduled commands, in the wording a person would use for them. */
const JOB_LABEL = {
  'loyalty:mature-points': 'Maturity',
  'loyalty:expire-points': 'Expiry',
  'loyalty:issue-birthday-rewards': 'Birthday rewards',
  'loyalty:recalculate-segments': 'Segmentation',
  'loyalty:replay-orders': 'Order replay',
};

/**
 * The rules in force, read from the current version.
 *
 * Every line is the stored value rather than the wording of the agreed UI, so
 * changing a rule in Settings changes this panel and the two cannot drift.
 */
function RulesInForce({ rules }) {
  if (rules.status !== 'ready') {
    return null;
  }

  const payload = rules.data.current.payload;
  const version = rules.data.current.version;

  return (
    <s-section heading="Rules currently in force">
      <s-unordered-list>
        <s-list-item>
          <s-text type="strong">
            £1 = {formatPoints(payload.earn_points_per_pound)} point
            {payload.earn_points_per_pound === 1 ? '' : 's'}
          </s-text>{' '}
          on all qualifying items — including sale, discounted and promotional
        </s-list-item>
        <s-list-item>
          Points stay{' '}
          <s-text type="strong">pending for {formatPoints(payload.pending_period_days)} days</s-text>{' '}
          before becoming available
        </s-list-item>
        <s-list-item>
          <s-text type="strong">
            {formatPoints(payload.voucher_threshold_points)} points ={' '}
            {formatPence(payload.voucher_value_pence)}
          </s-text>{' '}
          voucher value, rounded down
        </s-list-item>
        <s-list-item>
          Redemption capped at{' '}
          <s-text type="strong">
            {formatPence(payload.max_redemption_per_order_pence)} per order
          </s-text>
          , in {formatPence(payload.voucher_value_pence)} increments
        </s-list-item>
        <s-list-item>
          Minimum basket spend of{' '}
          <s-text type="strong">{formatPence(payload.min_basket_pence)}</s-text> before a voucher can
          be used
        </s-list-item>
        <s-list-item>
          <s-text type="strong">{formatPence(payload.birthday_reward_pence)}</s-text> birthday
          reward, once per year, expiring after{' '}
          {formatPoints(payload.birthday_reward_expiry_days)} days
        </s-list-item>
        <s-list-item>
          Points expire{' '}
          <s-text type="strong">{formatPoints(payload.points_expiry_days)} days</s-text> after they
          become available (D2)
        </s-list-item>
        <s-list-item>
          Redemption is {payload.online_redemption_enabled ? 'on' : 'off'} online and{' '}
          {payload.pos_redemption_enabled ? 'on' : 'off'} at the till
        </s-list-item>
      </s-unordered-list>

      <s-paragraph tone="neutral">
        Version {version}. Changed only from Settings → Rules, by an Administrator, and logged.
      </s-paragraph>
    </s-section>
  );
}

function Tile({ label, value, note }) {
  return (
    <s-box padding="base" border="base" borderRadius="base">
      <s-stack direction="block" gap="small-500">
        <s-text color="subdued">{label}</s-text>
        <s-heading>{value ?? NOT_HELD}</s-heading>
        {note ? <s-text color="subdued">{note}</s-text> : null}
      </s-stack>
    </s-box>
  );
}
