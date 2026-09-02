import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';

import App from './App.jsx';
import { renderRegistrationFailure, waitForPolaris } from './app/polarisGuard.js';

// Handle the server-side OAuth bounce out of the admin iframe.
if (window.location.pathname === '/exitiframe') {
  const redirectUri = new URLSearchParams(window.location.search).get('redirectUri');
  if (redirectUri) {
    window.open(decodeURIComponent(redirectUri), '_top');
  }
}

const container = document.getElementById('app');

function mount() {
  createRoot(container).render(
    <StrictMode>
      <BrowserRouter>
        <App />
      </BrowserRouter>
    </StrictMode>
  );
}

// Polaris web components (s-page, s-section, ...) are registered by the
// polaris.js script in index.html — NOT by app-bridge.js, and not by an npm
// import. V1: @shopify/polaris is deliberately not a dependency of this app.
//
// If that script did not run, every screen would render as unstyled text with
// no error anywhere. Rather than ship that silently, say so.
waitForPolaris().then((registered) => {
  if (registered) {
    mount();

    return;
  }

  renderRegistrationFailure(container);
});
