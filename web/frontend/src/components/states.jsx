import { NOT_HELD } from '../lib/format.js';

/** Work in progress, worded so it says what is being waited for. */
export function Loading({ label = 'Loading…' }) {
  return (
    <s-stack direction="inline" gap="base" alignItems="center">
      <s-spinner size="base" accessibilityLabel={label} />
      <s-text>{label}</s-text>
    </s-stack>
  );
}

/**
 * A refusal, in the words the server chose.
 *
 * The message is shown rather than replaced, because the API already words its
 * refusals for the person reading them — "This adjustment is larger than your
 * limit" is more use than "Something went wrong".
 */
export function ErrorBanner({ error, heading = 'That did not work' }) {
  if (!error) {
    return null;
  }

  return (
    <s-banner heading={heading} tone="critical">
      <s-paragraph>{error.message}</s-paragraph>
    </s-banner>
  );
}

export function EmptyState({ heading, children }) {
  return (
    <s-box padding="large">
      <s-stack direction="block" gap="small-100" alignItems="center">
        <s-heading>{heading}</s-heading>
        {children ? <s-paragraph tone="neutral">{children}</s-paragraph> : null}
      </s-stack>
    </s-box>
  );
}

/**
 * A value this programme does not hold.
 *
 * An em dash, never a zero and never a guess: the agreed UI shows fields that
 * later sprints will fill, and inventing a number for one of them would be
 * mistaken for real data at exactly the moment someone is deciding whether the
 * figures can be trusted.
 */
export function NotHeld({ label }) {
  return <s-text color="subdued" accessibilityLabel={label ? `${label}: not held` : undefined}>{NOT_HELD}</s-text>;
}
