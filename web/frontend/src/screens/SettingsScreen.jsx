import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { getRules, saveRules } from '../api/admin.js';
import RoleGate from '../app/RoleGate.jsx';
import { useSession } from '../app/SessionProvider.jsx';
import { useElementEvent } from '../app/useElementEvent.js';
import { ErrorBanner, Loading } from '../components/states.jsx';
import { formatDate, formatPence, formatPoints } from '../lib/format.js';

/**
 * A10 — Settings, the rules engine.
 *
 * Two things shape this screen and neither is cosmetic.
 *
 * **A version is a full snapshot.** `RuleSchema` rejects a partial payload
 * rather than merging it, because merging would carry forward a value the person
 * saving never saw. So the form has to send every rule — including the ones it
 * does not put a field on — and those are listed under "Carried forward
 * unchanged" rather than left invisible. A field nobody can see is still a field
 * they are saving.
 *
 * **A change is audited with the old and the new value.** The confirm step is
 * therefore a diff, not a yes/no: what the audit log will record is exactly what
 * is shown before the save, so nobody discovers afterwards what they changed.
 *
 * Administrator floor on the save. A Manager can read this screen — reading the
 * rules is a Viewer permission — and sees the form disabled.
 */

/** The editable fields, in the grouping and wording of the agreed UI. */
const GROUPS = [
  {
    heading: 'Earning & rewards',
    fields: [
      {
        key: 'earn_points_per_pound',
        label: 'Earn rate',
        help: 'Points earned per £1 of qualifying spend',
        kind: 'integer',
      },
      {
        key: 'voucher_threshold_points',
        label: 'Voucher threshold',
        help: 'Points required for one voucher increment',
        kind: 'integer',
      },
      {
        key: 'voucher_value_pence',
        label: 'Voucher value',
        // Also the redemption increment: the agreed UI lists the two
        // separately, but one value drives both and a second field would let
        // them disagree.
        help: 'Value of one increment, in £. This is also the redemption increment.',
        kind: 'pence',
      },
      {
        key: 'birthday_reward_pence',
        label: 'Birthday reward',
        help: 'Issued automatically once per year, in £',
        kind: 'pence',
      },
      {
        key: 'pending_period_days',
        label: 'Pending period',
        help: 'Days before earned points become available',
        kind: 'integer',
      },
      {
        key: 'points_expiry_days',
        label: 'Points expiry',
        // The agreed UI says months. The stored unit is days, and D2 starts the
        // clock at availability rather than at earning, so both are stated
        // rather than silently converted.
        help: 'Days after points become available, per D2',
        kind: 'integer',
      },
      {
        key: 'birthday_reward_expiry_days',
        label: 'Birthday reward expiry',
        help: 'Days from issue. Per D1 the points-derived voucher value has no expiry of its own',
        kind: 'integer',
      },
      {
        key: 'expiry_warning_days',
        label: 'Expiry warning window',
        help: 'How far ahead the console and the member warning look',
        kind: 'integer',
      },
    ],
  },
  {
    heading: 'Redemption rules',
    fields: [
      {
        key: 'min_basket_pence',
        label: 'Minimum basket spend',
        help: 'Before any voucher can be applied, in £',
        kind: 'pence',
      },
      {
        key: 'max_redemption_per_order_pence',
        label: 'Maximum per order',
        help: 'Voucher value cap, in £',
        kind: 'pence',
      },
      {
        key: 'online_redemption_enabled',
        label: 'Online redemption',
        help: 'Allow at Shopify checkout',
        kind: 'boolean',
      },
      {
        key: 'pos_redemption_enabled',
        label: 'POS redemption',
        help: 'Allow at the till',
        kind: 'boolean',
      },
    ],
  },
  {
    heading: 'Staff',
    fields: [
      {
        key: 'agent_adjustment_limit_points',
        label: 'Agent adjustment limit',
        help: 'Points an Agent may move in one adjustment, unless given a personal override',
        kind: 'integer',
      },
    ],
  },
];

const EDITABLE = new Set(GROUPS.flatMap((group) => group.fields.map((field) => field.key)));

const FIELD_BY_KEY = Object.fromEntries(
  GROUPS.flatMap((group) => group.fields).map((field) => [field.key, field]),
);

export default function SettingsScreen() {
  const { can } = useSession();
  const editable = can('edit_rules');

  const [loaded, setLoaded] = useState({ status: 'loading' });
  const [draft, setDraft] = useState(null);
  const [summary, setSummary] = useState('');
  const [submit, setSubmit] = useState({ status: 'idle' });
  const confirmRef = useRef(null);

  const reload = useCallback(() => {
    let cancelled = false;
    setLoaded({ status: 'loading' });

    getRules()
      .then((data) => {
        if (!cancelled) {
          setLoaded({ status: 'ready', data });
          setDraft(data.current.payload);
        }
      })
      .catch((error) => !cancelled && setLoaded({ status: 'error', error }));

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => reload(), [reload]);

  const saved = loaded.data?.current?.payload ?? null;

  const changes = useMemo(() => diff(saved, draft), [saved, draft]);
  const dirty = changes.length > 0;

  const save = useCallback(async () => {
    setSubmit({ status: 'saving' });

    try {
      const version = await saveRules({ rules: draft, changeSummary: summary || null });

      setSubmit({ status: 'saved', version });
      setSummary('');
      confirmRef.current?.hide?.();
      reload();
    } catch (error) {
      setSubmit({ status: 'error', error });
    }
  }, [draft, summary, reload]);

  if (loaded.status === 'loading') {
    return (
      <s-page heading="Settings">
        <s-section>
          <Loading label="Loading the rules in force…" />
        </s-section>
      </s-page>
    );
  }

  if (loaded.status === 'error') {
    return (
      <s-page heading="Settings">
        <s-section>
          <ErrorBanner error={loaded.error} heading="The rules could not be loaded" />
        </s-section>
      </s-page>
    );
  }

  const fieldErrors = submit.error?.code === 'validation_failed' ? submit.error.details : {};

  return (
    <s-page heading="Settings">
      <s-section>
        <s-stack direction="inline" gap="base" alignItems="center">
          <s-text color="subdued">
            Version {loaded.data.current.version}, in force since{' '}
            {formatDate(loaded.data.current.effective_from)}
          </s-text>

          <s-button disabled={!dirty || undefined} onClick={() => setDraft(saved)}>
            Discard
          </s-button>

          <RoleGate permission="edit_rules" mode="disable">
            <s-button
              variant="primary"
              command="--show"
              commandFor="rules-confirm"
              disabled={!dirty || undefined}
            >
              Save rules
            </s-button>
          </RoleGate>
        </s-stack>
      </s-section>

      {submit.status === 'saved' ? (
        <s-section>
          <s-banner heading="Rules saved" tone="success">
            <s-paragraph>
              Version {submit.version.version} is in force from now. Entries already posted keep the
              version they were calculated under.
            </s-paragraph>
          </s-banner>
        </s-section>
      ) : null}

      {submit.status === 'error' && submit.error?.code !== 'validation_failed' ? (
        <s-section>
          <ErrorBanner error={submit.error} heading="The rules were not saved" />
        </s-section>
      ) : null}

      {!editable ? (
        <s-section>
          <s-banner heading="You can read these rules but not change them" tone="info">
            <s-paragraph>Only an Administrator can save a new rule version.</s-paragraph>
          </s-banner>
        </s-section>
      ) : null}

      {GROUPS.map((group) => (
        <s-section key={group.heading} heading={group.heading}>
          <s-stack direction="block" gap="base">
            {group.fields.map((field) => (
              <RuleField
                key={field.key}
                field={field}
                value={draft?.[field.key]}
                error={fieldErrors[field.key]?.[0]}
                disabled={!editable}
                onChange={(value) => setDraft((current) => ({ ...current, [field.key]: value }))}
              />
            ))}
          </s-stack>
        </s-section>
      ))}

      <LivePreview draft={draft} />
      <CarriedForward payload={draft} />

      <s-section heading="Before you save">
        <s-paragraph>
          Rule changes apply from the moment they are saved and are written to the audit log with
          your name, the old value and the new value. Existing rewards already issued are not
          retrospectively changed.
        </s-paragraph>
      </s-section>

      <ConfirmModal
        ref={confirmRef}
        changes={changes}
        summary={summary}
        onSummary={setSummary}
        saving={submit.status === 'saving'}
        onSave={save}
      />
    </s-page>
  );
}

/**
 * One rule. Pence fields are entered in pounds, because nobody types 500 to
 * mean five pounds, and converted back at the edge.
 */
function RuleField({ field, value, error, disabled, onChange }) {
  const ref = useRef(null);

  const handle = useCallback(
    (event) => {
      const raw = event.currentTarget.value;

      if (field.kind === 'boolean') {
        onChange(Boolean(event.currentTarget.checked));

        return;
      }

      const number = Number(raw);

      if (!Number.isFinite(number)) {
        return;
      }

      onChange(field.kind === 'pence' ? Math.round(number * 100) : Math.round(number));
    },
    [field.kind, onChange],
  );

  useElementEvent(ref, field.kind === 'boolean' ? 'change' : 'input', handle);

  if (field.kind === 'boolean') {
    return (
      <s-stack direction="block" gap="small-500">
        <s-switch
          ref={ref}
          label={field.label}
          name={field.key}
          checked={value === true || undefined}
          disabled={disabled || undefined}
        />
        <s-text color="subdued">{field.help}</s-text>
        {error ? <s-text tone="critical">{error}</s-text> : null}
      </s-stack>
    );
  }

  return (
    <s-stack direction="block" gap="small-500">
      <s-number-field
        ref={ref}
        label={field.label}
        name={field.key}
        min={0}
        step={field.kind === 'pence' ? 0.01 : 1}
        inputMode="numeric"
        value={String(field.kind === 'pence' ? (value ?? 0) / 100 : (value ?? 0))}
        disabled={disabled || undefined}
      />
      <s-text color="subdued">{field.help}</s-text>
      {error ? <s-text tone="critical">{error}</s-text> : null}
    </s-stack>
  );
}

/**
 * What the draft means for one balance.
 *
 * Computed here rather than fetched, and that is safe in a way the adjustment
 * preview is not: nothing is being saved, no member is being shown a number, and
 * the rules being previewed do not exist on the server yet, so there is nothing
 * to ask. The moment a figure affects a member's balance it comes from the
 * server instead.
 */
function LivePreview({ draft }) {
  if (!draft) {
    return null;
  }

  const points = 340;
  const threshold = Math.max(1, draft.voucher_threshold_points ?? 1);
  const increments = Math.floor(points / threshold);
  const value = increments * (draft.voucher_value_pence ?? 0);
  const remainder = points % threshold;

  return (
    <s-section heading="Live preview">
      <s-stack direction="block" gap="small-500">
        <s-heading>
          {formatPoints(points)} points → {formatPence(value)}
        </s-heading>
        <s-text color="subdued">
          {formatPoints(increments * threshold)} points used · {formatPoints(remainder)} carried
          forward · {formatPoints(threshold - remainder)} points to the next{' '}
          {formatPence(draft.voucher_value_pence)}
        </s-text>
        <s-text color="subdued">
          Redeemable in {formatPence(draft.voucher_value_pence)} steps, up to{' '}
          {formatPence(draft.max_redemption_per_order_pence)} per order.
        </s-text>
        <s-paragraph tone="neutral">
          An illustration on a 340-point balance. Customers only ever see the £ figure; the points
          balance stays in the backend.
        </s-paragraph>
      </s-stack>
    </s-section>
  );
}

/**
 * The rules this form does not put a field on, but still saves.
 *
 * A full snapshot means these are part of every save whether or not anyone
 * looked at them, so they are shown rather than hidden. Several are held open by
 * decisions that are not closed — the qualification mode by C3, the earn base by
 * D3 — and a field for them here would imply a choice that has not been made.
 */
function CarriedForward({ payload }) {
  if (!payload) {
    return null;
  }

  const carried = Object.entries(payload).filter(([key]) => !EDITABLE.has(key));

  return (
    <s-section heading="Carried forward unchanged">
      <s-paragraph tone="neutral">
        A rule version is a full snapshot, so these are saved with every change. They have no field
        here because the decision behind each is still open, or because they are not an OSC setting.
      </s-paragraph>

      <s-table variant="auto">
        <s-table-header-row>
          <s-table-header listSlot="primary">Rule</s-table-header>
          <s-table-header>Value</s-table-header>
        </s-table-header-row>
        <s-table-body>
          {carried.map(([key, value]) => (
            <s-table-row key={key}>
              <s-table-cell>{key.replaceAll('_', ' ')}</s-table-cell>
              <s-table-cell>
                {typeof value === 'object' ? JSON.stringify(value) : String(value)}
              </s-table-cell>
            </s-table-row>
          ))}
        </s-table-body>
      </s-table>
    </s-section>
  );
}

/**
 * The confirm step: exactly what the audit log will record.
 *
 * A diff rather than a yes/no, because a rule change is audited with the old and
 * the new value and nobody should learn what they changed by reading the log
 * afterwards.
 */
function ConfirmModal({ ref, changes, summary, onSummary, saving, onSave }) {
  const summaryRef = useRef(null);

  useElementEvent(
    summaryRef,
    'input',
    useCallback((event) => onSummary(event.currentTarget.value ?? ''), [onSummary]),
  );

  return (
    <s-modal ref={ref} id="rules-confirm" heading="Save these rule changes?">
      <s-stack direction="block" gap="base">
        {changes.length === 0 ? (
          <s-paragraph>Nothing has changed.</s-paragraph>
        ) : (
          <s-table variant="auto">
            <s-table-header-row>
              <s-table-header listSlot="primary">Rule</s-table-header>
              <s-table-header>Before</s-table-header>
              <s-table-header>After</s-table-header>
            </s-table-header-row>
            <s-table-body>
              {changes.map((change) => (
                <s-table-row key={change.key}>
                  <s-table-cell>{change.label}</s-table-cell>
                  <s-table-cell>{change.before}</s-table-cell>
                  <s-table-cell>{change.after}</s-table-cell>
                </s-table-row>
              ))}
            </s-table-body>
          </s-table>
        )}

        <s-stack direction="block" gap="small-500">
          <s-text color="subdued">Why (optional)</s-text>
          <s-text-field
            ref={summaryRef}
            label="Change summary"
            labelAccessibilityVisibility="exclusive"
            name="change_summary"
            placeholder="Board decision August 2026"
            maxLength={500}
            value={summary}
          />
          <s-text color="subdued">
            Written to the audit log alongside the old and new values.
          </s-text>
        </s-stack>

        <s-paragraph tone="neutral">
          This saves a new version. The current one is kept, and every entry already posted stays
          calculated under the version in force when it happened.
        </s-paragraph>
      </s-stack>

      <s-button slot="secondary-actions" command="--hide" commandFor="rules-confirm">
        Cancel
      </s-button>

      <s-button
        slot="primary-action"
        variant="primary"
        loading={saving || undefined}
        disabled={changes.length === 0 || undefined}
        onClick={onSave}
      >
        Save rules
      </s-button>
    </s-modal>
  );
}

/** Field-level differences, worded the way the confirm step shows them. */
function diff(saved, draft) {
  if (!saved || !draft) {
    return [];
  }

  return Object.keys(draft)
    .filter((key) => EDITABLE.has(key))
    .filter((key) => saved[key] !== draft[key])
    .map((key) => ({
      key,
      label: FIELD_BY_KEY[key]?.label ?? key.replaceAll('_', ' '),
      before: present(key, saved[key]),
      after: present(key, draft[key]),
    }));
}

function present(key, value) {
  const kind = FIELD_BY_KEY[key]?.kind;

  if (kind === 'pence') {
    return formatPence(value);
  }

  if (kind === 'boolean') {
    return value ? 'on' : 'off';
  }

  return formatPoints(value);
}
