# Oxford Staging - Shopify embedded admin app

One integrated application following the architecture of Shopify's official PHP
app template (`Shopify/shopify-app-template-php`), on current library versions.

```
Oxford-Staging/
|- shopify.app.toml        Shopify CLI app config (scopes, webhooks, API version)
|- package.json            @shopify/cli
|- web/                    Laravel backend (the app)
|  |- shopify.web.toml     CLI: roles = ["backend"]
|  |- serve.mjs            Cross-platform `artisan serve` launcher for the CLI
|  |- app/Lib/             Shopify session storage, OAuth + webhook handlers
|  |- app/Services/        ShopifyAdminApi - Admin GraphQL helper
|  |- app/Exceptions/      ShopifyAdminApiException (renders itself as JSON)
|  |- app/Http/Middleware/ shopify.auth / shopify.installed / CSP
|  |- config/shopify.php   Credentials, scopes, API version, webhook topics
|  |- tests/Feature/       Foundation, webhook, config-drift and service tests
|  \- frontend/            React + Vite UI (same app, not a separate project)
|     |- shopify.web.toml  CLI: roles = ["frontend"]
|     \- src/              Polaris web components test screen
```

## Prerequisites

- PHP 8.3+, Composer 2, Node.js 22+, MySQL 8
- Shopify CLI 4.x, already authenticated (`shopify auth login`)

## Run it locally

From the **project root** (not `web/`):

```bash
npm run dev          # = shopify app dev
```

The first run links the app to your Partner account, creates the app if needed,
opens a tunnel, and writes `client_id` / `application_url` into
`shopify.app.toml`. It then injects `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET` and
`SHOPIFY_APP_URL` into the backend, runs `php artisan migrate`, and starts both
the Laravel server and the Vite dev server. Press `p` to open the app.

The CLI tunnel points at the Vite dev server, which proxies `/api/*` to Laravel,
so everything is served from one HTTPS origin.

## Database

Create the schema once, then let the CLI migrate:

```sql
CREATE DATABASE oxford_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Configure credentials in `web/.env` (`DB_*`). Shopify sessions live in
`shopify_sessions`, kept separate from Laravel's own `sessions` table.

## Endpoints

| Route | Auth | Purpose |
| --- | --- | --- |
| `GET /api/health` | none | Backend, MySQL and Shopify library status |
| `GET /api/shop` | `shopify.auth` | Authenticated Admin GraphQL query (shop info) |
| `GET /api/auth` | none | Starts OAuth |
| `GET /api/auth/callback` | none | Completes OAuth, stores the token |
| `POST /api/webhooks` | HMAC | All webhook topics |

## Admin GraphQL calls

`App\Services\ShopifyAdminApi` wraps `Shopify\Clients\Graphql` so the HTTP
status, the GraphQL `errors` array and rate limiting are checked in one place.
It returns the `data` payload, or throws `ShopifyAdminApiException`, which
renders itself as JSON on `api/*` routes.

```php
// In a controller behind the shopify.auth middleware
$data = ShopifyAdminApi::forRequest($request)->query($query, $variables);

// In a webhook handler, queued job or console command
$data = ShopifyAdminApi::forShop($shop)->query($query);
```

## Webhooks

Every topic is delivered to `POST /api/webhooks`, HMAC verified by
`Registry::process()` and dispatched to a handler in `app/Lib/Handlers/`.

| Topic | Handler | Declared in |
| --- | --- | --- |
| `app/uninstalled` | `AppUninstalled` - deletes the shop's sessions | Admin API after OAuth |
| `products/update` | `ProductsUpdate` - logs the product | Admin API after OAuth |
| `orders/paid` | `OrdersPaid` - queues earning | Admin API after OAuth |
| `orders/updated` | `OrdersPaid` - queues a recalculation after an edit | Admin API after OAuth |
| `orders/cancelled` | `OrdersCancelled` - queues a full reversal | Admin API after OAuth |
| `refunds/create` | `RefundsCreate` - queues a proportional reversal | Admin API after OAuth |
| `customers/data_request`, `customers/redact`, `shop/redact` | GDPR handlers - log only | toml only |

Every business handler does the same three things and nothing else: record the
delivery, queue the work, return. The 200 Shopify waits for must not depend on
an Admin API round trip - a slow endpoint gets throttled and eventually
unsubscribed, and a loyalty programme that has quietly stopped earning is not
something anyone notices quickly.

**The payload is a trigger, not the arithmetic input.** Every figure the ledger
acts on is re-read from the order in the queued job, because a payload can be
stale, can arrive out of order, and does not carry the discount allocation or
the quantities left after an edit.

**No topic may be declared in `shopify.app.toml`.** `shopify app deploy` rejects
the entire app version with:

```
App-specific webhook subscriptions are not supported when
use_legacy_install_flow is enabled.
```

That flag is a hard constraint for this app - `shopify/shopify-api` v6 has no
token exchange - so shop-specific registration through the Admin API is the only
mechanism available. Every business topic therefore lives in
`config('shopify.webhooks.topics')` and is registered after OAuth.

The catch that makes this easy to miss: **`shopify app dev` accepts declared
topics** and resolves them against the tunnel, so a configuration that works all
through development fails the moment someone runs `deploy`.
`ShopifyConfigurationTest` fails the build if a subscription is ever declared.

The GDPR topics are the exception - `[webhooks.privacy_compliance]` deploys
without complaint, and those topics cannot be created through the Admin API at
all.

Because nothing is declarative, webhooks exist for a shop only once it has
completed OAuth. Reauthorising a shop re-registers them, which is also how they
follow a new tunnel URL during development.

To see what Shopify actually holds - and against which URL, which is the usual
reason a webhook stops arriving after a tunnel restart:

```bash
cd web
php artisan shopify:webhooks              # list shop-scoped subs, flagging any [stale] URL
php artisan shopify:webhooks --register   # register config topics; refuses a non-public app URL
```

Note that `shopify app deploy` publishes `application_url` from
`shopify.app.toml`. While that is still the scaffold placeholder, deploying
would point every declarative webhook at a dead address, so let
`shopify app dev` set the tunnel URL instead.

### Dev notes: the tunnel, scope grants and webhook registration

**After any tunnel restart, re-run registration.** The tunnel URL is new every
time `shopify app dev` starts. Subscriptions registered against the previous one
still exist and Shopify still tries to deliver to them, so the app goes quiet
without erroring:

```bash
cd web
php artisan shopify:webhooks --register   # move them to the new tunnel
php artisan shopify:webhooks              # confirm: no topic marked [stale]
```

**A tunnel change needs a `deploy`, not just a restart.** Under
`use_legacy_install_flow` the OAuth `redirect_uri` is validated against the
**released** app version's `redirect_urls`, which only `shopify app deploy`
publishes - `shopify app dev` updates the *dev* session's URLs, not the released
ones. While `shopify.app.toml` still carried the scaffold placeholder
`https://shopify.dev/apps/default-app-home`, no grant against a tunnel could
complete: the consent screen appears, the merchant accepts, and Shopify has
nowhere valid to send them back to, so the callback never reaches Laravel and
the stored grant is untouched. Nothing errors.

Deploy **while `shopify app dev` is still running**, from a second terminal - so
the URL you publish is the tunnel that is actually up:

```bash
shopify app deploy --force
```

Stopping `dev` first is the trap: the next start allocates a *new* tunnel and
the URLs you just published are stale again.

**Scope grants and webhook registration only succeed while the tunnel is live.**
Both are round trips that end at the app's public URL - OAuth redirects the
merchant back to it, and Shopify validates a subscription against it. With
`shopify app dev` stopped, a re-authorisation in the admin has nowhere to land:
the merchant sees a success screen, no session row is written, and the app keeps
the old token and the old scopes. Check `storage/logs/laravel.log` for
`Registered <topic> webhook for <shop>` to know a grant actually arrived.

**No manual `SHOPIFY_API_KEY` / `SHOPIFY_API_SECRET` / `HOST` exports.** A plain
`php artisan` shell is not spawned by the CLI, so it inherits none of the
injected environment and `config('shopify.host')` falls back to
`http://localhost:8000`, which `--register` refuses. Exporting the values by
hand does not fix it - it hard-codes a tunnel URL that is wrong after the next
restart, and wrong in a way that looks like it worked.

Instead, `web/serve.mjs` - the backend command the CLI *does* spawn - writes
what the CLI injected to `web/storage/app/dev-tunnel.json` and deletes it when
the CLI stops. `App\Lib\DevTunnel` reads it, `ShopifyServiceProvider` fills in
any value the current process is missing, and every artisan command in the
`local` environment then reaches the Admin API on its own. The file is
gitignored and owner-only, because it carries the API secret.

If the CLI is not running, `DevTunnel` falls back to the URL in
`.shopify/dev-bundle/manifest.json`. That file outlives the CLI, so the command
says where the URL came from and warns that it may name a dead tunnel:

```bash
cd web
php artisan shopify:webhooks --register
# Registering against https://<tunnel> (from the running `shopify app dev` backend, recorded 2 minutes ago)
```

The one-liner for a fresh terminal, needing nothing but a running
`shopify app dev`:

```bash
cd web && php artisan shopify:webhooks --register && php artisan shopify:webhooks
```

## Tests

```bash
cd web && php artisan test
```

Tests run against in-memory SQLite with fake Shopify credentials, so no store
or tunnel is needed.

## Production build

```bash
npm run build        # = shopify app build
```

Vite compiles into `web/public/` (`index.html` + `assets/`), so Laravel serves
the React app from the same origin. Point your web root at `web/public`.

## Deployment requirements

**Four things this repository cannot supply for itself.** Each is on the
go-live checklist in `PROGRESS.md`. Without them the app installs, the console
works, and the loyalty programme quietly does nothing - which is the worst of
the available failure modes, because every screen looks healthy.

### 1. The scheduler - one cron entry

Maturity, expiry, birthday rewards, segmentation and the ledger check all run
from Laravel's scheduler. Nothing runs them otherwise.

```cron
* * * * * cd /path/to/web && php artisan schedule:run >> /dev/null 2>&1
```

That single entry is the whole requirement; the cadence of each sweep is in
`web/routes/console.php`. To see what is registered and when each is next due:

```bash
cd web && php artisan schedule:list
```

Every sweep is idempotent, so a missed window is caught up by the next run
rather than needing anyone to work out what was skipped.

**How you would know it is missing.** The Loyalty screen reports overdue
maturities and expiries counted from the ledger, and names when each sweep last
ran. A date that has stopped moving, or a backlog that only grows, is the
symptom.

### 2. A queue worker

The order and refund webhooks record the delivery and queue the work. With no
worker, deliveries pile up in the jobs table as `received` and no points ever
move.

```bash
cd web && php artisan queue:work --tries=5 --backoff=10,60,300,900
```

Run it under a supervisor (systemd, Supervisor, or the platform's own process
manager) so it restarts. `QUEUE_CONNECTION` must not be left as `sync` in
production: that would run the work inside the webhook request and reintroduce
exactly the slow-acknowledgement problem the queue exists to avoid.

**How you would know it is missing.** `webhook_events` fills with rows stuck in
`received`, and the Loyalty screen shows earning that never arrived.

### 3. The access scopes, granted

`shopify.app.toml` and `web/.env` request:

```
read_customers, write_customers, read_orders,
read_discounts, write_discounts, read_locations,
read_products, write_products
```

| Scope | What needs it |
| --- | --- |
| `read_orders` | Re-reading an order for earning and reversals (M3, M8) |
| `write_customers` | The voucher entitlement metafield the discount function reads (D5, V4) |
| `write_products` | The per-product exclusion flag the function reads (V6a) |
| `read_discounts`, `write_discounts` | Creating the function-backed automatic discount (V5) |
| `read_locations` | Naming the store on a till redemption (MD10) |

**Status: granted on the development store 31 Aug 2026**, except
`write_products`, which was added afterwards for V6a and needs one further
re-authorisation. The production store needs the whole set at go-live.

Under `use_legacy_install_flow = true` a scope change forces **every merchant to
re-authorise once** - the grant is the merchant's action, not a deploy step.

`read_all_orders` is requested separately through the Partner Dashboard and is
**still awaiting Shopify** (D7). Nothing depends on it: segmentation is built
from our own ledger plus the migrated last-spend date.

**How you would know one is missing.** The queued jobs log *"Could not re-read
order for loyalty"* and the delivery is marked `failed`; a metafield write logs
*"Could not write the loyalty voucher metafield"* and the customer is simply not
offered a discount. Nothing is lost in either case: fix the grant and replay.

### 4. The real app URL and redirect URLs

**The released app version still carries the scaffold placeholders.**
`shopify.app.toml` has:

```toml
application_url = "https://shopify.dev/apps/default-app-home"

[auth]
redirect_urls = [ "https://shopify.dev/apps/default-app-home/api/auth/callback" ]
```

Those are the CLI's defaults, not addresses this app is served from.

- **For production**, both must be set to the real hosted URL before
  `shopify app deploy`. Deploying with the placeholders publishes them, and
  every OAuth redirect and every webhook then points at an address that does not
  serve this app. **This is on the go-live checklist.**
- **For development**, leave them alone and run `shopify app dev`, which sets the
  tunnel URL for the session. That is why the placeholders have been survivable
  so far: nothing in development ever reads them.

Because webhooks are registered per shop against whatever URL was current at
OAuth (see *Webhooks* above), changing the app URL means re-authorising the shop
or re-running `php artisan shopify:webhooks --register` so the subscriptions
follow it.

### Recovering from any of the above

Idempotency is what makes recovery boring. Replaying an order that was already
processed correctly changes nothing, so replay a wider range than you think you
need:

```bash
cd web
php artisan loyalty:replay-orders --failed            # every failed or unprocessed delivery
php artisan loyalty:replay-orders --order=1042        # one order
php artisan loyalty:replay-orders --from=2026-09-01   # a window, --dry-run to look first
php artisan loyalty:verify-ledger                     # changes nothing, exits non-zero on drift
php artisan loyalty:rebuild-balances                  # corrects drift from the ledger
```

## A note on SQLite and MySQL

The test suite runs on in-memory SQLite; production runs on MySQL. **SQLite is
the more forgiving of the two**, and three defects have now reached the repo
because a green suite said nothing:

- an `unsignedInteger` column that MySQL refuses a negative value on, while
  SQLite does not enforce unsigned at all;
- MySQL's JSON columns not preserving key order, which made an unchanged field
  read as changed in both the rules diff and the audit log;
- a search parser whose bug only appears once account ids climb past a small
  number, which SQLite's per-test auto-increment reset hides.

If you change a migration, a `CHECK` constraint, an unsigned column, an enum or
anything that round-trips through a JSON column, run the suite against MySQL
before trusting it:

```bash
cd web
DB_CONNECTION=mysql DB_DATABASE=oxford_staging_test php artisan test
```
