/**
 * The left-hand navigation, in the order and grouping of the agreed UI
 * (v1.1 FINAL): six Programme areas, then two under Configuration.
 *
 * Each entry names the permission that makes it worth showing. An area a role
 * cannot read at all is left out of the menu rather than shown and then refused
 * — a Viewer has no use for a link to the audit log. The permissions themselves
 * come from the server; this file only says which one gates which link.
 */
export const NAVIGATION = [
  { group: 'Programme', label: 'Dashboard', path: '/', permission: 'view_overview' },
  { group: 'Programme', label: 'Customers', path: '/customers', permission: 'view_members' },
  { group: 'Programme', label: 'Loyalty', path: '/loyalty', permission: 'view_ledger' },
  { group: 'Programme', label: 'Voucher management', path: '/vouchers', permission: 'view_members' },
  { group: 'Programme', label: 'Transactions', path: '/transactions', permission: 'view_ledger' },
  { group: 'Programme', label: 'Reports', path: '/reports', permission: 'view_overview' },
  { group: 'Configuration', label: 'Settings', path: '/settings', permission: 'view_rules' },
  { group: 'Configuration', label: 'Audit logs', path: '/audit', permission: 'view_audit' },
];

export function visibleNavigation(can) {
  return NAVIGATION.filter((item) => can(item.permission));
}
