import Placeholder from '../components/Placeholder.jsx';

/**
 * Every navigable area Sprint 1 has not built.
 *
 * Kept together because each is the same component with different words, and
 * spreading eight one-line files across the tree would suggest there is more
 * here than there is. Each will be replaced by a real screen, at which point its
 * entry disappears from this file.
 */

export function VouchersScreen() {
  return (
    <Placeholder
      heading="Voucher management"
      subtitle="Create, monitor, cancel and reissue vouchers, filtered by status, expiry window or member."
      sprint="Sprint 2"
      module="A6, Module 4 + 8"
      delivers={[
        'Active, redeemed, expiring and cancelled voucher counts',
        'Cancel and reissue, each with a mandatory reason written to the audit log',
      ]}
    />
  );
}

export function TransactionsScreen() {
  return (
    <Placeholder
      heading="Transactions"
      subtitle="Every loyalty-affecting order, online and in store, with the staff and till reference for POS sales."
      sprint="Sprint 2"
      module="A7, Module 1 + 3"
      delivers={[
        'Order, member, channel, staff and till, order total and eligible spend',
        'Whether the points are pending or available, and any voucher used',
      ]}
    />
  );
}

export function ReportsScreen() {
  return (
    <Placeholder
      heading="Reports"
      subtitle="The five confirmed reports, over a selectable date range, exporting to PDF or CSV."
      sprint="Sprint 5"
      module="A8, Module 7"
      delivers={[
        'R1 Total loyalty members, with the active and lapsed split',
        'R2 New loyalty sign-ups, split online and in-store',
        'R3 Loyalty-driven orders and revenue as a percentage of total sales',
        'R4 Online versus in-store activity',
        'R5 Birthday reward activity',
      ]}
    />
  );
}

export function NotFoundScreen() {
  return (
    <Placeholder
      heading="Not found"
      subtitle="That address is not part of the Privilege Club console."
      sprint="—"
    />
  );
}
