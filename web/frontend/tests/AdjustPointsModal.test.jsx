import { fireEvent, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { adjustPoints } from '../src/api/admin.js';
import { ApiError } from '../src/api/client.js';
import AdjustPointsModal from '../src/components/AdjustPointsModal.jsx';
import {
  adjustmentPreviewFixture,
  findHeading,
  memberFixture,
  queryField,
  renderWithSession,
} from './support.jsx';

vi.mock('../src/api/admin.js', async () => (await import('./apiMock.js')).adminApiMock());

/**
 * A4 — Manual points adjustment.
 *
 * The projection, the reason requirement and the per-person limit are all the
 * server's, and these tests are mostly about proving that: what the modal shows
 * is what the endpoint said, and what it refuses is what the endpoint refused.
 */
const MEMBER = memberFixture();

function renderModal({ role = 'agent', onAdjusted } = {}) {
  return renderWithSession(<AdjustPointsModal member={MEMBER} onAdjusted={onAdjusted} />, { role });
}

/**
 * Type into one of the modal's fields the way the component listens for it.
 *
 * A Polaris control emits a real DOM event on the custom element, which is what
 * `useElementEvent` subscribes to; fireEvent dispatches it and wraps the React
 * update in act() so nothing warns.
 */
function type(label, value) {
  const field = queryField(label);

  field.value = value;
  fireEvent(field, new Event('input', { bubbles: true }));
}

function choose(label, value) {
  const field = queryField(label);

  field.value = value;
  fireEvent(field, new Event('change', { bubbles: true }));
}

function press(text) {
  fireEvent.click(screen.getByText(text));
}

beforeEach(() => {
  adjustPoints.mockResolvedValue(adjustmentPreviewFixture());
});

describe('the form', () => {
  it('offers the fields and the reason categories from the agreed UI', async () => {
    renderModal();

    await screen.findByText('Adjustment type');

    expect(screen.getByText('Add points')).toBeInTheDocument();
    expect(screen.getByText('Deduct points')).toBeInTheDocument();
    expect(queryField('Points')).not.toBeNull();
    expect(queryField('Reason category')).not.toBeNull();
    expect(queryField('Notes')).not.toBeNull();

    const options = [...document.querySelectorAll('s-option')].map((one) => one.textContent.trim());

    expect(options).toEqual([
      'Goodwill gesture',
      'Correction — missed earning',
      'Correction — duplicate earning',
      'Migration correction',
      'Other (describe below)',
    ]);

    expect(
      screen.getByText(/Minimum 10 characters. Visible in the audit log/),
    ).toBeInTheDocument();
  });

  it('names who it will be recorded as before anything is saved', async () => {
    renderModal();

    expect(await screen.findByText(/Recorded as/)).toBeInTheDocument();
    expect(screen.getByText('N. Mehra')).toBeInTheDocument();
    // The agreed UI predicts the reference; this cannot, because it is derived
    // from a ledger entry that does not exist yet.
    expect(screen.getByText(/a reference is generated when you save/)).toBeInTheDocument();
  });
});

describe('the projected balance', () => {
  it('waits for a number before asking for anything', async () => {
    renderModal();

    expect(
      await screen.findByText('Enter a number of points to see the new balance.'),
    ).toBeInTheDocument();
    expect(adjustPoints).not.toHaveBeenCalled();
  });

  /**
   * The line the agreed UI shows, and every figure in it comes from the server.
   * Computing it here would be a second implementation of the voucher ladder.
   */
  it('shows the projection the endpoint returned, and never computes one', async () => {
    renderModal();

    await screen.findByText('Adjustment type');
    type('Points', '50');

    expect(
      await screen.findByText(
        'New balance will be 390 points → £15 available (90 pts carried forward)',
      ),
    ).toBeInTheDocument();

    // A preview carries no idempotency key: it writes nothing, so there is
    // nothing to make idempotent.
    expect(adjustPoints).toHaveBeenCalledWith(MEMBER.id, {
      direction: 'add',
      points: 50,
      preview: true,
    });
  });

  it('previews a deduction as a deduction', async () => {
    renderModal();

    await screen.findByText('Adjustment type');
    press('Deduct points');
    type('Points', '40');

    await waitFor(() =>
      expect(adjustPoints).toHaveBeenCalledWith(MEMBER.id, {
        direction: 'deduct',
        points: 40,
        preview: true,
      }),
    );
  });

  /**
   * Over the limit is reported by the preview rather than refused, so the modal
   * can say why before anyone writes a reason for an adjustment that was never
   * going to be accepted.
   */
  it('warns an agent that a preview is over their limit', async () => {
    adjustPoints.mockResolvedValue(
      adjustmentPreviewFixture({
        limit: { points: 500, requested_points: 900, exceeded: true },
      }),
    );

    renderModal({ role: 'agent' });

    await screen.findByText('Adjustment type');
    type('Points', '900');

    expect(
      await screen.findByText(/Over your limit of 500 points in one adjustment/),
    ).toBeInTheDocument();
  });
});

describe('saving', () => {
  it('sends the reason with the adjustment and reports it upward', async () => {
    const onAdjusted = vi.fn();

    renderModal({ onAdjusted });

    await screen.findByText('Adjustment type');
    type('Points', '50');
    choose('Reason category', 'goodwill');
    type('Notes', 'Replacement for a damaged acer, agreed with the store manager.');

    adjustPoints.mockResolvedValue({ preview: false, entry: { id: 4 }, reference: 'ADJ-4' });

    press('Save adjustment');

    await waitFor(() => expect(onAdjusted).toHaveBeenCalled());

    expect(adjustPoints).toHaveBeenLastCalledWith(
      MEMBER.id,
      {
        direction: 'add',
        points: 50,
        reason_category: 'goodwill',
        notes: 'Replacement for a damaged acer, agreed with the store manager.',
      },
      // One key per modal, so a double click cannot post twice.
      { idempotencyKey: expect.any(String) },
    );
  });

  /**
   * The reason rule lives on the server — `AuditLogger` refuses
   * `points.adjusted` without one — so the form sends the request and shows what
   * came back per field. A rule enforced only here is a rule that is not
   * enforced.
   */
  it('shows the server refusal for notes that are too short, against the notes field', async () => {
    renderModal();

    await screen.findByText('Adjustment type');
    type('Points', '50');
    choose('Reason category', 'goodwill');
    type('Notes', 'fixed it');

    adjustPoints.mockRejectedValue(
      new ApiError(422, 'validation_failed', 'Some of what was sent could not be accepted.', {
        notes: ['Give at least 10 characters, so this still makes sense in six months.'],
      }),
    );

    press('Save adjustment');

    expect(
      await screen.findByText(
        'Give at least 10 characters, so this still makes sense in six months.',
      ),
    ).toBeInTheDocument();
  });

  it('shows the server refusal for a missing reason category', async () => {
    renderModal();

    await screen.findByText('Adjustment type');
    type('Points', '50');

    adjustPoints.mockRejectedValue(
      new ApiError(422, 'validation_failed', 'Some of what was sent could not be accepted.', {
        reason_category: ['Choose a reason category. Every adjustment is explained in the audit log.'],
        notes: ['Describe the adjustment. Every adjustment is explained in the audit log.'],
      }),
    );

    press('Save adjustment');

    expect(
      await screen.findByText(/Choose a reason category/),
    ).toBeInTheDocument();
    expect(screen.getByText(/Describe the adjustment/)).toBeInTheDocument();
  });

  /**
   * The limit is per person and lives on the server. Both the message and the
   * two numbers in the details are shown, because "something went wrong" would
   * leave the Agent with no idea who to ask.
   */
  it('shows the limit message and its details inline when an agent is over their limit', async () => {
    renderModal({ role: 'agent' });

    await screen.findByText('Adjustment type');
    type('Points', '900');
    choose('Reason category', 'goodwill');
    type('Notes', 'A larger gesture than this agent may make on their own.');

    adjustPoints.mockRejectedValue(
      new ApiError(
        409,
        'adjustment_limit_exceeded',
        'This adjustment is larger than your limit. Ask a Manager to make it.',
        { requested_points: 900, limit_points: 500 },
      ),
    );

    press('Save adjustment');

    expect(await findHeading('This adjustment is over your limit')).toBeInTheDocument();
    expect(
      screen.getByText('This adjustment is larger than your limit. Ask a Manager to make it.'),
    ).toBeInTheDocument();
    expect(
      screen.getByText('You asked for 900 points; your limit is 500 in one adjustment.'),
    ).toBeInTheDocument();
  });

  it('reports any other refusal in the words the server used', async () => {
    renderModal();

    await screen.findByText('Adjustment type');
    type('Points', '50');
    choose('Reason category', 'goodwill');
    type('Notes', 'A perfectly good reason, ten characters and more.');

    adjustPoints.mockRejectedValue(
      new ApiError(409, 'account_merged', 'This account was merged. Work on the surviving account instead.'),
    );

    press('Save adjustment');

    expect(await findHeading('The adjustment was not saved')).toBeInTheDocument();
    expect(
      screen.getByText('This account was merged. Work on the surviving account instead.'),
    ).toBeInTheDocument();
  });
});
