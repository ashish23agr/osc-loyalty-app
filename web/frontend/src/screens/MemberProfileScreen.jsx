import { useCallback, useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';

import { getMember, getMemberLedger } from '../api/admin.js';
import RoleGate from '../app/RoleGate.jsx';
import { useElementEvent } from '../app/useElementEvent.js';
import AdjustPointsModal, { ADJUST_MODAL_ID } from '../components/AdjustPointsModal.jsx';
import { EmptyState, ErrorBanner, Loading, NotHeld } from '../components/states.jsx';
import {
  formatBirthday,
  formatDate,
  formatDateTime,
  formatDelta,
  formatPence,
  formatPoints,
  initials,
  NOT_HELD,
} from '../lib/format.js';
import {
  CHANNEL,
  consentLabel,
  ENROLMENT,
  ENTRY_STATUS,
  ENTRY_TYPE,
  REWARD_STATE,
  REWARD_TYPE,
  SEGMENT,
  lookup,
} from '../lib/labels.js';

/**
 * A3 — Customer profile, the full loyalty record.
 *
 * One unified record: contact details, card numbers, both balances, the voucher
 * figures and every ledger movement. The profile call answers all of it bar the
 * movements, which are paged, so the screen costs two requests rather than six.
 *
 * Where the agreed UI shows a field this programme does not hold — a mobile
 * number, or the Klaviyo sync state that arrives in Sprint 4 — the field is
 * rendered as an em dash. Inventing a plausible value would be mistaken for real
 * data at exactly the moment someone is deciding whether the figures are
 * trustworthy.
 */
const LEDGER_PER_PAGE = 10;

export default function MemberProfileScreen() {
  const { id } = useParams();
  const [profile, setProfile] = useState({ status: 'loading' });
  const [tab, setTab] = useState('ledger');

  // Bumped when an adjustment is saved. Both the profile and the ledger are
  // re-read from the server rather than patched in place: the balance after an
  // adjustment is the ledger's answer, and a locally-patched number that later
  // disagreed with it would be the worst kind of wrong.
  const [reloads, setReloads] = useState(0);
  const reload = useCallback(() => setReloads((current) => current + 1), []);

  useEffect(() => {
    let cancelled = false;
    setProfile({ status: 'loading' });

    getMember(id)
      .then((data) => !cancelled && setProfile({ status: 'ready', data }))
      .catch((error) => !cancelled && setProfile({ status: 'error', error }));

    return () => {
      cancelled = true;
    };
  }, [id, reloads]);

  if (profile.status === 'loading') {
    return (
      <s-page heading="Member">
        <s-section>
          <Loading label="Loading the member record…" />
        </s-section>
      </s-page>
    );
  }

  if (profile.status === 'error') {
    return (
      <s-page heading="Member">
        <s-section>
          <ErrorBanner
            error={profile.error}
            heading={profile.error?.isNotFound ? 'No such member' : 'The member could not be loaded'}
          />
        </s-section>
      </s-page>
    );
  }

  const member = profile.data;

  return (
    <s-page heading={member.display_name ?? 'Member with no name held'}>
      <s-section>
        <s-stack direction="inline" gap="base" alignItems="center">
          <s-text color="subdued">
            Member since {formatDate(member.enrolled_at)} ·{' '}
            {lookup(ENROLMENT, member.enrolment_channel, 'enrolled')}
          </s-text>

          {/* Gated so a Viewer never sees an action the server would refuse.
              The floor here and the floor on the endpoint are the same one. */}
          <RoleGate permission="adjust_points">
            <s-button variant="primary" command="--show" commandFor={ADJUST_MODAL_ID}>
              Adjust points
            </s-button>
          </RoleGate>

          <RoleGate permission="view_members">
            <s-button disabled>Issue voucher</s-button>
          </RoleGate>

          {member.shopify_customer_id ? (
            <s-link href={`shopify://admin/customers/${member.shopify_customer_id}`}>
              View in Shopify
            </s-link>
          ) : null}
        </s-stack>
      </s-section>

      {member.is_merged ? (
        <s-section>
          <s-banner heading="This account was merged" tone="warning">
            <s-paragraph>
              Its history is kept so it can be explained, but nothing can be posted to it. Work on
              account {member.merged_into_account_id} instead.
            </s-paragraph>
          </s-banner>
        </s-section>
      ) : null}

      <IdentityCard member={member} />
      <BalanceCards member={member} />

      <s-section>
        <s-button-group gap="base">
          <s-button
            variant={tab === 'ledger' ? 'primary' : 'tertiary'}
            onClick={() => setTab('ledger')}
          >
            Points ledger
          </s-button>
          <s-button
            variant={tab === 'vouchers' ? 'primary' : 'tertiary'}
            onClick={() => setTab('vouchers')}
          >
            Vouchers ({member.rewards.length})
          </s-button>
        </s-button-group>
      </s-section>

      {tab === 'ledger' ? (
        <LedgerTab memberId={id} reloads={reloads} />
      ) : (
        <VouchersTab rewards={member.rewards} />
      )}

      {/* Mounted for any role that may adjust, and only then: the trigger needs
          a target, and a role without the permission has no trigger. */}
      <RoleGate permission="adjust_points">
        <AdjustPointsModal member={member} onAdjusted={reload} />
      </RoleGate>
    </s-page>
  );
}

function IdentityCard({ member }) {
  const segment = lookup(SEGMENT, member.segment, SEGMENT.unknown);
  const consent = consentLabel(member.email_marketing_consent);

  return (
    <s-section heading="Member details">
      <s-stack direction="inline" gap="base" alignItems="center">
        <s-avatar initials={initials(member.first_name, member.last_name)} size="base" />
      </s-stack>

      <s-grid gridTemplateColumns="1fr 1fr 1fr 1fr" gap="base">
        <Field label="Email — primary identifier">
          {member.has_no_email ? (
            <s-badge tone="critical">No email held</s-badge>
          ) : (
            <s-text>{member.email}</s-text>
          )}
        </Field>

        {/* Not a field this programme holds. Shopify owns the phone number and
            no sprint brings it across, so it is shown as not held rather than
            quietly dropped from the agreed layout. */}
        <Field label="Mobile">
          <NotHeld label="Mobile" />
        </Field>

        <Field label="Postcode — supporting">
          <s-text>{member.postcode ?? NOT_HELD}</s-text>
        </Field>

        <Field label="Date of birth">
          <s-text>{formatBirthday(member)}</s-text>
        </Field>

        <Field label="Card number">
          <s-text>{member.card_number}</s-text>
        </Field>

        <Field label="Legacy card">
          <s-text>{member.legacy_card_number ?? NOT_HELD}</s-text>
        </Field>

        <Field label="Segment">
          <s-badge tone={segment.tone}>{segment.label}</s-badge>
          {/* Segmentation is derived nightly, never assigned, so the date it
              was last worked out is part of what the pill means. */}
          {member.segment_calculated_at ? (
            <s-text color="subdued">as at {formatDate(member.segment_calculated_at)}</s-text>
          ) : null}
        </Field>

        <Field label="Marketing consent">
          <s-stack direction="inline" gap="small-100" alignItems="center">
            <s-badge tone={consent.tone}>{consent.label}</s-badge>
            {/* The agreed UI says "synced to Klaviyo" here. Klaviyo is Sprint 4
                and nothing syncs yet, so the claim is not made. */}
            <s-text color="subdued">Klaviyo sync {NOT_HELD}</s-text>
          </s-stack>
        </Field>
      </s-grid>
    </s-section>
  );
}

function BalanceCards({ member }) {
  const { balances, rules } = member;
  const incrementLabel = formatPence(rules.voucher_value_pence);

  return (
    <s-section heading="Balances">
      <s-grid gridTemplateColumns="1fr 1fr 1fr 1fr 1fr" gap="base">
        <Metric
          label="Available points (backend)"
          value={formatPoints(balances.points_available)}
          note="Not shown to the customer"
        />

        <Metric
          label="Pending points"
          value={formatPoints(balances.points_pending)}
          tone="warning"
          note={
            member.pending_clears_at
              ? `Clears ${formatDate(member.pending_clears_at)} · ${rules.pending_period_days}-day period`
              : 'Nothing pending'
          }
        />

        <Metric
          label="Available voucher value"
          value={formatPence(balances.voucher_balance_pence)}
          note={`${formatPoints(balances.points_committed_to_voucher)} pts used · ${formatPoints(
            balances.remainder_points,
          )} pts carried forward`}
        />

        <Metric
          label={`Points to next ${incrementLabel}`}
          value={formatPoints(balances.points_to_next_increment)}
          note={`${formatPoints(rules.voucher_threshold_points)} points is ${incrementLabel}`}
        />

        <Metric
          label="Lifetime spend"
          value={formatPence(member.lifetime_spend_pence)}
          note={
            member.last_purchase_at
              ? `Last purchase ${formatDate(member.last_purchase_at)}`
              : 'No qualifying purchase yet'
          }
        />
      </s-grid>
    </s-section>
  );
}

function LedgerTab({ memberId, reloads = 0 }) {
  const [page, setPage] = useState(1);
  const [result, setResult] = useState({ status: 'loading' });
  const tableRef = useRef(null);

  useEffect(() => {
    let cancelled = false;
    setResult((current) => ({ ...current, status: 'loading' }));

    getMemberLedger(memberId, { page, perPage: LEDGER_PER_PAGE })
      .then((data) => !cancelled && setResult({ status: 'ready', data }))
      .catch((error) => !cancelled && setResult({ status: 'error', error }));

    return () => {
      cancelled = true;
    };
  }, [memberId, page, reloads]);

  useElementEvent(
    tableRef,
    'nextpage',
    useCallback(() => setPage((current) => current + 1), []),
  );
  useElementEvent(
    tableRef,
    'previouspage',
    useCallback(() => setPage((current) => Math.max(1, current - 1)), []),
  );

  const entries = result.data?.data ?? [];
  const meta = result.data?.meta ?? {};

  return (
    <s-section>
      <ErrorBanner error={result.error} heading="The movement history could not be loaded" />

      <s-table
        ref={tableRef}
        variant="auto"
        paginate
        loading={result.status === 'loading' || undefined}
        hasPreviousPage={page > 1 || undefined}
        hasNextPage={page < (meta.last_page ?? 1) || undefined}
      >
        <s-table-header-row>
          <s-table-header listSlot="primary">Date &amp; time</s-table-header>
          <s-table-header>Type</s-table-header>
          <s-table-header>Detail</s-table-header>
          <s-table-header listSlot="inline">Status</s-table-header>
          <s-table-header>Channel / staff</s-table-header>
          <s-table-header>Reference</s-table-header>
          <s-table-header listSlot="labeled">Points</s-table-header>
          <s-table-header listSlot="labeled">Available</s-table-header>
        </s-table-header-row>

        <s-table-body>
          {entries.map((entry) => (
            <LedgerRow key={entry.id} entry={entry} />
          ))}
        </s-table-body>
      </s-table>

      {result.status === 'ready' && entries.length === 0 ? (
        <EmptyState heading="No movements yet">
          Points appear here as soon as this member earns, redeems or is adjusted.
        </EmptyState>
      ) : null}

      <s-paragraph tone="neutral">
        Every movement is an immutable ledger entry — nothing is edited in place.
      </s-paragraph>
    </s-section>
  );
}

function LedgerRow({ entry }) {
  const status = lookup(ENTRY_STATUS, entry.status, ENTRY_STATUS.available);

  return (
    <s-table-row>
      <s-table-cell>{formatDateTime(entry.occurred_at)}</s-table-cell>
      <s-table-cell>{lookup(ENTRY_TYPE, entry.entry_type, entry.entry_type)}</s-table-cell>
      <s-table-cell>{detailFor(entry)}</s-table-cell>

      <s-table-cell>
        <s-badge tone={status.tone}>
          {entry.entry_type === 'earn' && entry.status === 'pending' && entry.matures_at
            ? `Pending to ${formatDate(entry.matures_at)}`
            : status.label}
        </s-badge>
      </s-table-cell>

      <s-table-cell>
        {lookup(CHANNEL, entry.channel, entry.channel)}
        {/* The store, where the movement carries one. A till sale does; an
            online order does not, and inventing "Online store" for it would
            claim a location the order never had. */}
        {entry.shopify_location_id ? ` · Store ${entry.shopify_location_id}` : ''}
        {entry.staff_reference ? ` · Staff ${entry.staff_reference}` : ''}
      </s-table-cell>

      <s-table-cell>{entry.reference}</s-table-cell>
      <s-table-cell>{formatDelta(entry.points)}</s-table-cell>
      <s-table-cell>{formatPoints(entry.available_after)}</s-table-cell>
    </s-table-row>
  );
}

/**
 * The Detail column, built from the fields the entry carries.
 *
 * A reason is always the best explanation when one exists, because a person
 * wrote it about this exact movement. Failing that, the qualifying spend
 * explains an earn, and the type explains the rest.
 */
function detailFor(entry) {
  if (entry.reason) {
    return entry.reason;
  }

  if (entry.entry_type === 'earn' && entry.qualifying_value_pence !== null) {
    return `Qualifying spend ${formatPence(entry.qualifying_value_pence)}`;
  }

  switch (entry.entry_type) {
    case 'maturity':
      return 'Pending points became available';
    case 'expiry':
      return 'Points reached their expiry date';
    case 'redemption':
      return 'Voucher value redeemed';
    case 'redemption_restore':
      return 'Redemption released back to the account';
    case 'earn_reversal':
      return 'Earning reversed';
    case 'opening_balance':
      return 'Migrated opening balance';
    default:
      return NOT_HELD;
  }
}

function VouchersTab({ rewards }) {
  if (rewards.length === 0) {
    return (
      <s-section>
        <EmptyState heading="No vouchers issued">
          The birthday reward and any goodwill voucher appear here. The points-derived voucher value
          is not a voucher — it rises and falls with the balance above.
        </EmptyState>
      </s-section>
    );
  }

  return (
    <s-section>
      <s-table variant="auto">
        <s-table-header-row>
          <s-table-header listSlot="primary">Type</s-table-header>
          <s-table-header listSlot="labeled">Value</s-table-header>
          <s-table-header>Issued</s-table-header>
          <s-table-header>Expires</s-table-header>
          <s-table-header listSlot="inline">Status</s-table-header>
          <s-table-header>Reason</s-table-header>
        </s-table-header-row>

        <s-table-body>
          {rewards.map((reward) => {
            const state = lookup(REWARD_STATE, reward.state, REWARD_STATE.issued);

            return (
              <s-table-row key={reward.id}>
                <s-table-cell>{lookup(REWARD_TYPE, reward.reward_type, reward.reward_type)}</s-table-cell>
                <s-table-cell>{formatPence(reward.value_pence)}</s-table-cell>
                <s-table-cell>{formatDate(reward.issued_at)}</s-table-cell>
                <s-table-cell>{formatDate(reward.expires_at)}</s-table-cell>
                <s-table-cell>
                  <s-badge tone={state.tone}>{state.label}</s-badge>
                </s-table-cell>
                <s-table-cell>{reward.cancelled_reason ?? NOT_HELD}</s-table-cell>
              </s-table-row>
            );
          })}
        </s-table-body>
      </s-table>
    </s-section>
  );
}

function Field({ label, children }) {
  return (
    <s-stack direction="block" gap="small-500">
      <s-text color="subdued">{label}</s-text>
      {children}
    </s-stack>
  );
}

function Metric({ label, value, note, tone }) {
  return (
    <s-box padding="base" border="base" borderRadius="base">
      <s-stack direction="block" gap="small-500">
        <s-text color="subdued">{label}</s-text>
        <s-heading>
          <s-text tone={tone}>{value}</s-text>
        </s-heading>
        {note ? <s-text color="subdued">{note}</s-text> : null}
      </s-stack>
    </s-box>
  );
}
