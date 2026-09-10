import '@shopify/ui-extensions/preact';
import {render} from 'preact';

/**
 * V11 spike — customer account deployability under `use_legacy_install_flow`.
 *
 * Static on purpose. It reads no API, fetches nothing and imports nothing of
 * ours, so exactly one thing can make it fail: the target not deploying or not
 * rendering. A spike that also read the member's balance would fail for at
 * least three reasons — a missing `customer_read_customers` scope, an
 * undeclared customer metafield, or a dead app URL — and could not distinguish
 * between them, which is the mistake the POS `appUrl` defect taught.
 *
 * If this renders on the Profile page, route A is available and the real
 * surface can be planned on it. If it does not, the answer is route B (an app
 * proxy page carrying `logged_in_customer_id`) and no time should be spent
 * forcing this one — see the fallback ladder in PROGRESS.md.
 */
export default async function () {
  render(<Spike />, document.body);
}

function Spike() {
  return (
    <s-section heading="Privilege Club">
      <s-paragraph>
        This block is a deployability check. If you can read it, a customer
        account UI extension renders on this store under the app-managed OAuth
        install flow.
      </s-paragraph>
    </s-section>
  );
}
