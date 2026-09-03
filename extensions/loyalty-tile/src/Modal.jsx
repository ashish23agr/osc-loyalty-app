import '@shopify/ui-extensions/preact';
import {render} from 'preact';
import {useCallback, useEffect, useMemo, useState} from 'preact/hooks';

import {TillApi} from './lib/api.js';
import {enrolmentProblem, toPayload, validate} from './lib/enrolment.js';
import {canScan, fromScan, hintFor} from './lib/lookup.js';
import {formatPence, stepDown, stepUp} from './lib/money.js';
import {refusalFor} from './lib/reasons.js';
import {isConnected} from './lib/tileState.js';

export default async () => {
  render(<Extension />, document.body);
};

/**
 * The Privilege Club modal (M7, D6, D8, C7, V7).
 *
 * Three screens, in the order the agreed UI puts them:
 *
 *   lookup   one box, every identifier, plus scan where the device has one
 *   member   the balance, and a £5-step redeem control
 *   enrol    for a customer who is not a member yet
 *
 * **Validation happens before an amount is offered.** The member screen asks the
 * server what this basket could take, and the step control is built from that
 * answer. So the number on screen is one the ladder has already agreed to, and
 * pressing redeem cannot fail on arithmetic. A till with a queue behind it is
 * the worst possible place to discover a limit.
 *
 * Every action carries the pinned staff member (M7, C9). The role floors from
 * Sprint 1 still govern: a POS action is attributed, not exempted.
 */
function Extension() {
  const session = shopify.session?.currentSession ?? {};
  const [connected, setConnected] = useState(isConnected(shopify.connectivity?.current?.value));
  const [screen, setScreen] = useState('lookup');
  const [member, setMember] = useState(null);

  const api = useMemo(
    () => new TillApi({
      getSessionToken: () => shopify.session.getSessionToken(),
      appUrl: shopify.environment?.appUrl ?? '',
    }),
    [],
  );

  // TEMPORARY DIAGNOSTIC - REVERT THIS COMMIT ONCE READ. 3 Sep 2026.
  // The POS tile's search request never reaches the backend: confirmed (a),
  // zero lines for /api/admin/members in the `shopify app dev` request log
  // while laravel.log and the audit log show nothing either. The suspicion is
  // that `shopify.environment` is not part of the POS API surface at all - it
  // appears in no POS type and `appUrl` appears nowhere in
  // @shopify/ui-extensions - so `appUrl` resolves to '' and the fetch goes to
  // a relative path that never leaves the extension sandbox. These three lines
  // establish what the host actually provides at runtime; nothing is fixed
  // here, and the injection site above is deliberately untouched.
  useEffect(() => {
    const safe = (value) => {
      try {
        return JSON.stringify(value);
      } catch (cause) {
        return 'UNSERIALISABLE: ' + String(cause);
      }
    };

    const url = `${api.appUrl}/api/admin/members?q=TEST&per_page=10`;

    console.log('[oxford-diag] shopify.environment =', safe(shopify.environment));
    console.log('[oxford-diag] shopify.session.currentSession =', safe(shopify.session?.currentSession));
    console.log('[oxford-diag] constructed search URL =', url);

    // On-device fallback: console output may not surface on POS for Android,
    // and a rebuild that yields nothing readable is a wasted round trip. The
    // URL is the one line that decides this, so it goes somewhere guaranteed
    // visible. `appUrl` empty shows as a leading slash with no host.
    shopify.toast?.show?.(`diag: ${url || '(empty)'}`, {duration: 10000});
  }, [api]);

  useEffect(() => {
    const unsubscribe = shopify.connectivity?.current?.subscribe?.((state) => {
      setConnected(isConnected(state));
    });

    return () => unsubscribe?.();
  }, []);

  // C7: nothing in here works without a connection, and the sale must not stop.
  if (!connected) {
    return <OfflineNotice />;
  }

  if (screen === 'enrol') {
    return (
      <Enrol
        api={api}
        onCancel={() => setScreen('lookup')}
        onEnrolled={(enrolled) => {
          setMember(enrolled);
          setScreen('member');
        }}
      />
    );
  }

  if (screen === 'member' && member) {
    return (
      <MemberView
        api={api}
        member={member}
        session={session}
        onBack={() => {
          setMember(null);
          setScreen('lookup');
        }}
      />
    );
  }

  return (
    <Lookup
      api={api}
      onFound={(found) => {
        setMember(found);
        setScreen('member');
      }}
      onEnrol={() => setScreen('enrol')}
    />
  );
}

/**
 * C7, the confirmed offline rule: record the sale, add the points afterwards.
 *
 * The second sentence is the one that matters. Points are not lost — the earning
 * webhook posts them when connectivity returns — and the assistant needs to know
 * that before deciding what to do about the customer in front of them.
 */
function OfflineNotice() {
  return (
    <s-navigator>
      <s-screen name="Offline" title="Privilege Club">
        <s-banner tone="warning" heading="Loyalty is unavailable">
          <s-text>
            The till has no connection to the loyalty system, so balances cannot be
            checked and vouchers cannot be redeemed.
          </s-text>
        </s-banner>
        <s-section heading="Carry on with the sale">
          <s-text>
            Take payment as normal. Points for this sale are added automatically once
            the till is back online — nothing is lost and nothing needs re-entering.
          </s-text>
        </s-section>
      </s-screen>
    </s-navigator>
  );
}

/** One box, every identifier, and a scan button only where a scanner exists. */
function Lookup({api, onFound, onEnrol}) {
  const [term, setTerm] = useState('');
  const [results, setResults] = useState(null);
  const [searching, setSearching] = useState(false);
  const [problem, setProblem] = useState(null);

  const scanners = shopify.scanner?.sources?.current?.value ?? [];

  const search = useCallback(async (value) => {
    const query = (value ?? '').trim();

    if (query === '') {
      return;
    }

    setSearching(true);
    setProblem(null);

    const response = await api.search(query);

    setSearching(false);

    if (!response.ok) {
      setProblem(
        response.error === 'unreachable'
          ? 'The loyalty system could not be reached. Continue the sale.'
          : 'That search could not be run.',
      );

      return;
    }

    setResults(response.payload?.data ?? []);
  }, [api]);

  // V7: a scan is typed input that arrived faster, so it takes the same path.
  useEffect(() => {
    const unsubscribe = shopify.scanner?.scannerData?.current?.subscribe?.((scan) => {
      const scanned = fromScan(scan?.data);

      if (scanned !== '') {
        setTerm(scanned);
        search(scanned);
      }
    });

    return () => unsubscribe?.();
  }, [search]);

  return (
    <s-navigator>
      <s-screen name="Lookup" title="Find a member">
        <s-section>
          <s-search-field
            label="Email, name, postcode or card number"
            placeholder="Search"
            value={term}
            onInput={(event) => setTerm(event.target.value)}
            onSubmit={() => search(term)}
          />
          <s-text color="subdued">{hintFor(term)}</s-text>

          {canScan(scanners) ? (
            <s-text color="subdued">Or scan the member's card.</s-text>
          ) : null}
        </s-section>

        {problem ? <s-banner tone="critical"><s-text>{problem}</s-text></s-banner> : null}

        {searching ? <s-text>Searching…</s-text> : null}

        {results !== null && results.length === 0 ? (
          <s-section heading="No member found">
            <s-text>Nobody matches that. Check the spelling, or enrol them now.</s-text>
            <s-button onPress={onEnrol}>Enrol this customer</s-button>
          </s-section>
        ) : null}

        {results?.length ? (
          <s-section heading={`${results.length} match${results.length === 1 ? '' : 'es'}`}>
            {results.map((found) => (
              <s-clickable key={found.id} onPress={() => onFound(found)}>
                <s-stack direction="block">
                  <s-text type="strong">{found.display_name ?? found.card_number}</s-text>
                  <s-text color="subdued">
                    {[found.email, found.postcode, found.legacy_card_number]
                      .filter(Boolean)
                      .join(' · ')}
                  </s-text>
                </s-stack>
              </s-clickable>
            ))}
          </s-section>
        ) : null}

        <s-section>
          <s-button variant="secondary" onPress={onEnrol}>Enrol a new member</s-button>
        </s-section>
      </s-screen>
    </s-navigator>
  );
}

/**
 * The member, and what this basket may take.
 *
 * The quote is asked for as soon as the screen opens, so the redeem control is
 * built from an answer the server has already given rather than from the
 * member's balance. That is the difference between offering £45 because the
 * basket qualifies for £45, and offering £50 because the member holds £50 and
 * then failing.
 */
function MemberView({api, member, session, onBack}) {
  const [quote, setQuote] = useState(null);
  const [chosen, setChosen] = useState(null);
  const [held, setHeld] = useState(null);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState(null);

  const cart = shopify.cart?.current?.value ?? {};

  // The basket, in pence. The eligible subtotal is the server's to decide from
  // the rule version, so the till sends what it has and does not second-guess.
  const basketTotalPence = Math.round(Number(cart.subtotal ?? cart.grandTotal ?? 0) * 100);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      const response = await api.quote({
        memberId: member.id,
        eligibleSubtotalPence: basketTotalPence,
        basketTotalPence,
      });

      if (cancelled) {
        return;
      }

      if (!response.ok) {
        setProblem(refusalFor(null));

        return;
      }

      setQuote(response.payload);
      setChosen(response.payload.redeemable_pence || null);
    })();

    return () => {
      cancelled = true;
    };
  }, [api, member.id, basketTotalPence]);

  // From the quote, not hard-coded: the increment is a rule, and changing it in
  // Settings has to change this control without a redeploy.
  const increment = quote?.increment_pence ?? 500;
  const maximum = quote?.redeemable_pence ?? 0;

  const redeem = useCallback(async () => {
    setBusy(true);
    setProblem(null);

    const response = await api.hold({
      memberId: member.id,
      eligibleSubtotalPence: basketTotalPence,
      basketTotalPence,
      requestedPence: chosen,
      locationId: session.locationId ?? null,
      // C9/M7: who is actually at the till, which POS reports separately from
      // the signed-in user.
      staffMemberId: session.staffMemberId ?? session.userId ?? null,
    });

    setBusy(false);

    if (!response.ok) {
      setProblem(refusalFor(null));

      return;
    }

    if (response.payload.redemption === null) {
      setProblem(refusalFor(response.payload.reason));

      return;
    }

    const redemption = response.payload.redemption;

    // D6: attach to the sale. The title is what prints on the receipt, and it
    // carries the reference so the receipt and the audit log share one id.
    await shopify.cart.applyCartDiscount(
      'FixedAmount',
      `Privilege Club ${formatPence(redemption.amount_pence)} (${redemption.reference})`,
      (redemption.amount_pence / 100).toFixed(2),
    );

    setHeld(redemption);
    shopify.toast?.show?.(`${formatPence(redemption.amount_pence)} applied to the sale`);
  }, [api, member.id, basketTotalPence, chosen, session]);

  return (
    <s-navigator>
      <s-screen name="Member" title={member.display_name ?? 'Member'}>
        <s-section heading="Balance">
          <s-stack direction="block">
            <s-text type="strong">{formatPence(member.voucher_balance_pence)} of voucher value</s-text>
            <s-text color="subdued">{member.points_available ?? 0} points available</s-text>
            {member.legacy_card_number ? (
              <s-text color="subdued">Card {member.legacy_card_number}</s-text>
            ) : null}
          </s-stack>
        </s-section>

        {held ? (
          <s-banner tone="success" heading="Applied to this sale">
            <s-text>
              {formatPence(held.amount_pence)} — reference {held.reference}. Points are
              taken when the sale is paid for.
            </s-text>
          </s-banner>
        ) : null}

        {problem ? (
          <s-banner tone="warning" heading={problem.title}>
            <s-text>{problem.detail}</s-text>
          </s-banner>
        ) : null}

        {!held && quote && quote.reason ? (
          <s-banner tone="warning" heading={refusalFor(quote.reason).title}>
            <s-text>{refusalFor(quote.reason).detail}</s-text>
          </s-banner>
        ) : null}

        {!held && quote && !quote.reason && maximum > 0 ? (
          <s-section heading="Redeem">
            <s-text color="subdued">
              Up to {formatPence(maximum)} against this basket.
            </s-text>
            <s-stack direction="inline">
              <s-button
                variant="secondary"
                disabled={busy || chosen <= increment}
                onPress={() => setChosen(stepDown(chosen, increment))}
              >
                −{formatPence(increment)}
              </s-button>
              <s-text type="strong">{formatPence(chosen)}</s-text>
              <s-button
                variant="secondary"
                disabled={busy || chosen >= maximum}
                onPress={() => setChosen(stepUp(chosen, maximum, increment))}
              >
                +{formatPence(increment)}
              </s-button>
            </s-stack>
            <s-button variant="primary" disabled={busy} onPress={redeem}>
              {busy ? 'Applying…' : `Apply ${formatPence(chosen)}`}
            </s-button>
          </s-section>
        ) : null}

        <s-section>
          <s-button variant="secondary" onPress={onBack}>Find another member</s-button>
        </s-section>
      </s-screen>
    </s-navigator>
  );
}

/**
 * Enrol at the till (M1, MD1, MD2, D10).
 *
 * The duplicate check is the server's, because only the server can see the other
 * members. A duplicate is not treated as a failure: it means this person is
 * already enrolled, and the useful response is to open their account.
 */
function Enrol({api, onCancel, onEnrolled}) {
  const [form, setForm] = useState({firstName: '', lastName: '', email: '', postcode: '', dobMonth: '', dobDay: ''});
  const [errors, setErrors] = useState({});
  const [problem, setProblem] = useState(null);
  const [busy, setBusy] = useState(false);

  const field = (name) => (event) => setForm({...form, [name]: event.target.value});

  const submit = useCallback(async () => {
    const check = validate(form);

    setErrors(check.errors);
    setProblem(null);

    if (!check.valid) {
      return;
    }

    setBusy(true);

    const response = await api.enrol(toPayload(form));

    setBusy(false);

    if (!response.ok) {
      setProblem(enrolmentProblem(response));

      return;
    }

    onEnrolled(response.payload);
  }, [api, form, onEnrolled]);

  return (
    <s-navigator>
      <s-screen name="Enrol" title="Enrol a member">
        {problem ? (
          <s-banner
            tone={problem.kind === 'duplicate' ? 'warning' : 'critical'}
            heading={problem.title}
          >
            <s-text>{problem.detail}</s-text>
          </s-banner>
        ) : null}

        <s-section>
          <s-text-field label="First name" value={form.firstName} onInput={field('firstName')} />
          <s-text-field
            label="Surname"
            value={form.lastName}
            error={errors.lastName}
            onInput={field('lastName')}
          />
          {/* MD1: optional. A member with no email is a supported member. */}
          <s-email-field
            label="Email (optional)"
            value={form.email}
            error={errors.email}
            onInput={field('email')}
          />
          <s-text-field
            label="Postcode"
            value={form.postcode}
            error={errors.postcode}
            onInput={field('postcode')}
          />
        </s-section>

        {/* MD2: a day and month with no year is a complete birthday here. */}
        <s-section heading="Birthday (optional)">
          <s-text color="subdued">A day and month is enough — no year needed.</s-text>
          <s-stack direction="inline">
            <s-number-field label="Day" value={form.dobDay} onInput={field('dobDay')} />
            <s-number-field label="Month" value={form.dobMonth} onInput={field('dobMonth')} />
          </s-stack>
        </s-section>

        <s-section>
          <s-button variant="primary" disabled={busy} onPress={submit}>
            {busy ? 'Enrolling…' : 'Enrol'}
          </s-button>
          <s-button variant="secondary" onPress={onCancel}>Cancel</s-button>
        </s-section>
      </s-screen>
    </s-navigator>
  );
}
