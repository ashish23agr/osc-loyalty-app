import { NavMenu } from '@shopify/app-bridge-react';
import { Link, Outlet } from 'react-router-dom';

import { useSession } from './SessionProvider.jsx';
import { visibleNavigation } from './navigation.js';

/**
 * The shell every screen renders inside.
 *
 * The navigation is App Bridge's `ui-nav-menu`, which the Shopify admin lifts
 * out of the iframe and renders as the app's own left-hand menu — so the links
 * sit where a merchant expects them, in the order and grouping of the agreed
 * UI, rather than inside a second menu the app drew for itself. The first
 * anchor is the home link by App Bridge convention.
 *
 * A plain anchor is what ui-nav-menu needs, but react-router has to own the
 * click or every navigation would reload the whole app; Link gives both.
 */
export default function AppFrame() {
  const { can } = useSession();
  const items = visibleNavigation(can);

  return (
    <>
      <NavMenu>
        {/* App Bridge treats the first anchor as the home link. */}
        <Link to="/" rel="home">
          Privilege Club
        </Link>
        {items.map((item) => (
          <Link key={item.path} to={item.path}>
            {item.label}
          </Link>
        ))}
      </NavMenu>

      <Outlet />
    </>
  );
}
