/**
 * Talking to the loyalty app from the till.
 *
 * Every call carries a POS session token in the Authorization header, which the
 * app's EnsureShopifySession middleware decodes — the same middleware the admin
 * console goes through, because V3 established that all three token types are
 * the same shape. There is no separate POS authentication path to keep in step.
 *
 * A failed call is returned, never thrown. At a till, an unhandled rejection is
 * a blank screen with a customer waiting; a returned error is something the tile
 * can put words around.
 */

export class TillApi {
  constructor({getSessionToken, appUrl, fetchImpl = fetch}) {
    this.getSessionToken = getSessionToken;
    this.appUrl = appUrl?.replace(/\/$/, '') ?? '';
    this.fetchImpl = fetchImpl;
  }

  /**
   * An absolute https origin, and nothing else will do.
   *
   * https because POS refuses to fetch any non-HTTPS request outright, and
   * absolute because a relative path does not resolve to the app from inside
   * the extension sandbox.
   */
  static isUsableBase(appUrl) {
    return typeof appUrl === 'string' && /^https:\/\/[^/\s]+$/.test(appUrl);
  }

  async request(path, {method = 'GET', body = null} = {}) {
    // An unconfigured base URL is a refusal, not a relative request.
    //
    // This is the defect of 3 Sep 2026 and the reason it took a day to find.
    // `appUrl` resolved to '', so every call went to the relative
    // `/api/admin/...`, which from the POS sandbox reaches nothing: no request
    // arrived, no error was raised, and the tile simply returned no members.
    // POS gives an extension no way to discover its own app URL — confirmed
    // against the 2026-07 docs — so an empty base is always a build
    // misconfiguration and never a transient condition. Refusing loudly here
    // costs one comparison and turns a silent day into a legible message, which
    // is the same bargain the rest of this class already makes.
    if (!TillApi.isUsableBase(this.appUrl)) {
      return {ok: false, error: 'no_app_url', status: 0};
    }

    let token;

    try {
      token = await this.getSessionToken();
    } catch (cause) {
      return {ok: false, error: 'no_session', status: 0, cause};
    }

    // getSessionToken returns undefined when the signed-in user lacks app
    // permission. That is a real, explainable state rather than a fault.
    if (!token) {
      return {ok: false, error: 'no_session', status: 0};
    }

    let response;

    try {
      response = await this.fetchImpl(`${this.appUrl}/api/admin${path}`, {
        method,
        headers: {
          Authorization: `Bearer ${token}`,
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: body === null ? undefined : JSON.stringify(body),
      });
    } catch (cause) {
      // Almost always the network. The tile turns this into the offline state
      // rather than an error, because that is what it means at a till.
      return {ok: false, error: 'unreachable', status: 0, cause};
    }

    const payload = await response.json().catch(() => null);

    if (!response.ok) {
      return {
        ok: false,
        status: response.status,
        error: payload?.error?.code ?? 'request_failed',
        message: payload?.error?.message ?? null,
        payload,
      };
    }

    return {ok: true, status: response.status, payload};
  }

  /** The lookup modal: one box, every identifier (email, name, postcode, card). */
  search(term) {
    return this.request(`/members?q=${encodeURIComponent(term)}&per_page=10`);
  }

  member(id) {
    return this.request(`/members/${id}`);
  }

  /** Validate BEFORE offering an amount. Writes nothing. */
  quote({memberId, eligibleSubtotalPence, basketTotalPence}) {
    return this.request('/redemptions/quote', {
      method: 'POST',
      body: {
        loyalty_account_id: memberId,
        eligible_subtotal_pence: eligibleSubtotalPence,
        basket_total_pence: basketTotalPence,
      },
    });
  }

  /** Hold the quote the assistant chose, attributed to the staff member. */
  hold({memberId, eligibleSubtotalPence, basketTotalPence, requestedPence, locationId, staffMemberId, tillCurrency}) {
    return this.request('/redemptions', {
      method: 'POST',
      body: {
        loyalty_account_id: memberId,
        eligible_subtotal_pence: eligibleSubtotalPence,
        basket_total_pence: basketTotalPence,
        channel: 'pos',
        requested_pence: requestedPence,
        shopify_location_id: locationId,
        staff_member_id: staffMemberId,
        // V18: the till denominates the discount, not the shop. Sent so the
        // server can refuse rather than silently apply GBP 50 of points as
        // USD 50 at a US-located till.
        till_currency: tillCurrency,
      },
    });
  }

  void(reference) {
    return this.request(`/redemptions/${encodeURIComponent(reference)}`, {method: 'DELETE'});
  }

  /** Enrol at the till. The server enforces the duplicate-email check (D10). */
  enrol(member) {
    return this.request('/members', {method: 'POST', body: member});
  }
}
