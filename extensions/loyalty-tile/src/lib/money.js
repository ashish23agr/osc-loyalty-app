/**
 * Money and the redeem control's arithmetic (D8).
 *
 * Everything is integer pence, as it is everywhere else in this programme. The
 * £5 step is not hard-coded: it comes from the quote the server returned, so
 * changing the increment in Settings changes the control without a redeploy.
 */

/** "£45", "£7.50" — whole pounds lose the pence, as the agreed UI writes them. */
export function formatPence(pence) {
  if (pence === null || pence === undefined || Number.isNaN(pence)) {
    return '—';
  }

  const pounds = pence / 100;

  return `£${Number.isInteger(pounds) ? pounds : pounds.toFixed(2)}`;
}

/**
 * The amounts the step control may offer, largest last.
 *
 * Every one is a whole increment and none exceeds what the server agreed to, so
 * the control cannot produce a value the ladder would refuse. That is the point:
 * validation happens before an amount is ever on screen, not after the assistant
 * presses redeem.
 */
export function steps(maxPence, incrementPence) {
  if (!maxPence || !incrementPence || incrementPence <= 0 || maxPence < incrementPence) {
    return [];
  }

  const count = Math.floor(maxPence / incrementPence);
  const offers = [];

  for (let step = 1; step <= count; step++) {
    offers.push(step * incrementPence);
  }

  return offers;
}

/** One step up, never past the agreed maximum. */
export function stepUp(currentPence, maxPence, incrementPence) {
  return Math.min(maxPence, currentPence + incrementPence);
}

/** One step down, never below a single increment. */
export function stepDown(currentPence, incrementPence) {
  return Math.max(incrementPence, currentPence - incrementPence);
}
