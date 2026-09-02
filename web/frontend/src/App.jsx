import { Route, Routes } from 'react-router-dom';

import AppFrame from './app/AppFrame.jsx';
import { SessionGate, SessionProvider } from './app/SessionProvider.jsx';
import AuditScreen from './screens/AuditScreen.jsx';
import CustomersScreen from './screens/CustomersScreen.jsx';
import DashboardScreen from './screens/DashboardScreen.jsx';
import LoyaltyScreen from './screens/LoyaltyScreen.jsx';
import MemberProfileScreen from './screens/MemberProfileScreen.jsx';
import SettingsScreen from './screens/SettingsScreen.jsx';
import {
  NotFoundScreen,
  ReportsScreen,
  TransactionsScreen,
  VouchersScreen,
} from './screens/placeholders.jsx';

/**
 * The Privilege Club console.
 *
 * Every area of the agreed UI is routable from the first commit, so the
 * navigation has no dead links; the screens Sprint 1 has not reached render a
 * stub that says which sprint owns them. SessionGate holds everything back
 * until the role is known, because a screen that renders before the permission
 * set arrives would flash actions it then has to withdraw.
 */
export default function App({ loadSession }) {
  return (
    <SessionProvider loadSession={loadSession}>
      <SessionGate>
        <Routes>
          <Route element={<AppFrame />}>
            <Route index element={<DashboardScreen />} />
            <Route path="customers" element={<CustomersScreen />} />
            <Route path="customers/:id" element={<MemberProfileScreen />} />
            <Route path="loyalty" element={<LoyaltyScreen />} />
            <Route path="vouchers" element={<VouchersScreen />} />
            <Route path="transactions" element={<TransactionsScreen />} />
            <Route path="reports" element={<ReportsScreen />} />
            <Route path="settings" element={<SettingsScreen />} />
            <Route path="audit" element={<AuditScreen />} />
            <Route path="*" element={<NotFoundScreen />} />
          </Route>
        </Routes>
      </SessionGate>
    </SessionProvider>
  );
}
