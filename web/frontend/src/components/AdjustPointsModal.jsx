import { useCallback, useEffect, useRef, useState } from 'react';

import { adjustPoints } from '../api/admin.js';
import { useSession } from '../app/SessionProvider.jsx';
import { useElementEvent } from '../app/useElementEvent.js';
import { formatDateTime, formatPence, formatPoints } from '../lib/format.js';
import { REASON_CATEGORIES } from '../lib/labels.js';

/**
 * A4 — Manual points adjustment.
 *
 * Three things about this form are not negotiable, and each is enforced by the
 * server rather than here:
 *
 *   1. **The projected balance comes from the endpoint.** `preview: true` writes
 *      nothing and returns the projection, so "New balance will be 390 points →
 *      £15 available" is the same arithmetic that will actually run. Computing
 *      it on this side would be a second implementation of the voucher ladder,
 *      and the one shown to a member of staff would be the untested one.
 *   2. **The reason is mandatory in the ledger and the audit log**, and
 *      `AuditLogger` refuses `points.adjusted` without one. This form shows the
 *      rule up front and then renders whatever the server says per field — it
 *      does not gate the request, because a client-side-only rule is a rule that
 *      is not enforced.
 *   3. **The per-person limit is the server's.** An Agent over their limit gets
 *      `409 adjustment_limit_exceeded` with the limit and the request in the
 *      details, and both are shown, not flattened into "something went wrong".
 *
 * The modal element is always mounted so the trigger's `commandFor` has a
 * target; the form state resets when it closes.
 */
export const ADJUST_MODAL_ID = 'adjust-points-modal';

const EMPTY_FORM = { direction: 'add', points: '', reasonCategory: '', notes: '' };

export default function AdjustPointsModal({ member, onAdjusted }) {
  const { staff } = useSession();
  const modalRef = useRef(null);

  const [form, setForm] = useState(EMPTY_FORM);
  const [preview, setPreview] = useState({ status: 'idle' });
  const [submit, setSubmit] = useState({ status: 'idle' });

  // One key per modal session, so a double click or a retry after a dropped
  // response returns the original adjustment instead of posting a second one.
  const [idempotencyKey, setIdempotencyKey] = useState(newKey);

  const points = Number.parseInt(form.points, 10);
  const hasPoints = Number.isInteger(points) && points > 0;

  const reset = useCallback(() => {
    setForm(EMPTY_FORM);
    setPreview({ status: 'idle' });
    setSubmit({ status: 'idle' });
    setIdempotencyKey(newKey());
  }, []);

  useElementEvent(modalRef, 'hide', reset);

  // The projection follows the number being typed, debounced so a four-digit
  // adjustment is not four requests.
  useEffect(() => {
    if (!hasPoints) {
      setPreview({ status: 'idle' });

      return undefined;
    }

    let cancelled = false;

    const timer = setTimeout(() => {
      setPreview((current) => ({ ...current, status: 'loading' }));

      adjustPoints(member.id, { direction: form.direction, points, preview: true })
        .then((data) => !cancelled && setPreview({ status: 'ready', data }))
        .catch((error) => !cancelled && setPreview({ status: 'error', error }));
    }, 250);

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [member.id, form.direction, points, hasPoints]);

  const save = useCallback(async () => {
    setSubmit({ status: 'saving' });

    try {
      const result = await adjustPoints(
        member.id,
        {
          direction: form.direction,
          points,
          reason_category: form.reasonCategory,
          notes: form.notes,
        },
        { idempotencyKey },
      );

      setSubmit({ status: 'saved', result });
      onAdjusted?.(result);
      modalRef.current?.hide?.();
    } catch (error) {
      setSubmit({ status: 'error', error });
    }
  }, [member.id, form, points, idempotencyKey, onAdjusted]);

  const fieldErrors = submit.error?.code === 'validation_failed' ? submit.error.details : {};
  const limitExceeded = submit.error?.code === 'adjustment_limit_exceeded';

  return (
    <s-modal ref={modalRef} id={ADJUST_MODAL_ID} heading="Adjust points">
      <s-stack direction="block" gap="base">
        <s-stack direction="block" gap="small-500">
          <s-text color="subdued">{member.display_name ?? 'This member'}</s-text>
          <s-text>
            {formatPoints(member.balances.points_available)} points ·{' '}
            {formatPence(member.balances.voucher_balance_pence)} available
          </s-text>
        </s-stack>

        {limitExceeded ? <LimitBanner error={submit.error} /> : null}

        {submit.status === 'error' && !limitExceeded && submit.error?.code !== 'validation_failed' ? (
          <s-banner heading="The adjustment was not saved" tone="critical">
            <s-paragraph>{submit.error.message}</s-paragraph>
          </s-banner>
        ) : null}

        <Radios
          label="Adjustment type"
          value={form.direction}
          options={[
            ['add', 'Add points'],
            ['deduct', 'Deduct points'],
          ]}
          onChange={(direction) => setForm((current) => ({ ...current, direction }))}
        />

        <Field
          label="Points"
          required
          error={fieldErrors.points?.[0]}
          help={<Projection preview={preview} hasPoints={hasPoints} />}
        >
          <NumberField
            label="Points"
            value={form.points}
            onChange={(value) => setForm((current) => ({ ...current, points: value }))}
          />
        </Field>

        <Field label="Reason category" required error={fieldErrors.reason_category?.[0]}>
          <SelectField
            label="Reason category"
            value={form.reasonCategory}
            placeholder="Choose a reason"
            options={Object.entries(REASON_CATEGORIES)}
            onChange={(reasonCategory) => setForm((current) => ({ ...current, reasonCategory }))}
          />
        </Field>

        <Field
          label="Notes"
          required
          error={fieldErrors.notes?.[0]}
          help="Minimum 10 characters. Visible in the audit log and on the member's record."
        >
          <TextArea
            label="Notes"
            value={form.notes}
            onChange={(notes) => setForm((current) => ({ ...current, notes }))}
          />
        </Field>

        {/* The agreed UI predicts the reference here. This one does not: the
            reference is derived from the ledger entry id, which does not exist
            until the adjustment is posted. */}
        <s-box padding="base" background="subdued" borderRadius="base">
          <s-paragraph>
            Recorded as <s-text type="strong">{staffName(staff)}</s-text> ·{' '}
            {formatDateTime(new Date().toISOString())} · a reference is generated when you save.
          </s-paragraph>
        </s-box>
      </s-stack>

      <s-button slot="secondary-actions" command="--hide" commandFor={ADJUST_MODAL_ID}>
        Cancel
      </s-button>

      <s-button
        slot="primary-action"
        variant="primary"
        loading={submit.status === 'saving' || undefined}
        onClick={save}
      >
        Save adjustment
      </s-button>
    </s-modal>
  );
}

/**
 * The projected balance, straight from the server.
 *
 * Reads as the agreed UI writes it: "New balance will be 390 points → £15
 * available (40 pts carried forward)".
 */
function Projection({ preview, hasPoints }) {
  if (!hasPoints) {
    return 'Enter a number of points to see the new balance.';
  }

  if (preview.status === 'loading' || preview.status === 'idle') {
    return 'Working out the new balance…';
  }

  if (preview.status === 'error') {
    return preview.error.message;
  }

  const { projection, limit } = preview.data;

  const line =
    `New balance will be ${formatPoints(projection.points_available_after)} points → ` +
    `${formatPence(projection.voucher_balance_pence_after)} available ` +
    `(${formatPoints(projection.remainder_points_after)} pts carried forward)`;

  if (limit.exceeded) {
    // Reported, not refused: the modal says why before anyone writes a reason
    // for an adjustment that was never going to be accepted.
    return (
      <s-stack direction="block" gap="small-500">
        <s-text>{line}</s-text>
        <s-text tone="critical">
          Over your limit of {formatPoints(limit.points)} points in one adjustment. A Manager can
          make this one.
        </s-text>
      </s-stack>
    );
  }

  return line;
}

function LimitBanner({ error }) {
  return (
    <s-banner heading="This adjustment is over your limit" tone="critical">
      <s-paragraph>{error.message}</s-paragraph>
      <s-paragraph>
        You asked for {formatPoints(error.details.requested_points)} points; your limit is{' '}
        {formatPoints(error.details.limit_points)} in one adjustment.
      </s-paragraph>
    </s-banner>
  );
}

function Field({ label, required, error, help, children }) {
  return (
    <s-stack direction="block" gap="small-500">
      <s-text color="subdued">
        {label}
        {required ? ' *' : ''}
      </s-text>
      {children}
      {error ? <s-text tone="critical">{error}</s-text> : null}
      {help ? <s-text color="subdued">{help}</s-text> : null}
    </s-stack>
  );
}

function Radios({ label, value, options, onChange }) {
  return (
    <s-stack direction="block" gap="small-500">
      <s-text color="subdued">{label}</s-text>
      <s-button-group gap="small-100">
        {options.map(([optionValue, optionLabel]) => (
          <s-button
            key={optionValue}
            variant={value === optionValue ? 'primary' : 'secondary'}
            onClick={() => onChange(optionValue)}
          >
            {optionLabel}
          </s-button>
        ))}
      </s-button-group>
    </s-stack>
  );
}

function NumberField({ label, value, onChange }) {
  const ref = useRef(null);

  useElementEvent(
    ref,
    'input',
    useCallback((event) => onChange(event.currentTarget.value ?? ''), [onChange]),
  );

  return (
    <s-number-field
      ref={ref}
      label={label}
      labelAccessibilityVisibility="exclusive"
      name="points"
      min={1}
      step={1}
      inputMode="numeric"
      value={value}
    />
  );
}

function SelectField({ label, value, placeholder, options, onChange }) {
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
      name="reason_category"
      placeholder={placeholder}
      value={value}
    >
      {options.map(([optionValue, optionLabel]) => (
        <s-option key={optionValue} value={optionValue}>
          {optionLabel}
        </s-option>
      ))}
    </s-select>
  );
}

function TextArea({ label, value, onChange }) {
  const ref = useRef(null);

  useElementEvent(
    ref,
    'input',
    useCallback((event) => onChange(event.currentTarget.value ?? ''), [onChange]),
  );

  return (
    <s-text-area
      ref={ref}
      label={label}
      labelAccessibilityVisibility="exclusive"
      name="notes"
      rows={3}
      maxLength={400}
      value={value}
    />
  );
}

/**
 * The staff name falls back to the identifier, because resolving one needs the
 * protected `read_users` scope this app deliberately does not request.
 */
function staffName(staff) {
  return staff?.staff_name ?? `Staff ${staff?.shopify_staff_id ?? ''}`.trim();
}

function newKey() {
  return globalThis.crypto?.randomUUID?.() ?? `adj-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
