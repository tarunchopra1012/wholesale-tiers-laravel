# Decisions

A running log of choices made during this build, and why. One entry per
decision: what was chosen, what the alternative was, and the reason. Written
as it happens, because the reasoning is the part that evaporates.

---

## 19 Sep 2026 — development environment

### PHP 8.4 base image, not 8.3

Laravel 13 pulls in Symfony 8, and sixteen of its components declare
`php: >=8.4.1`. A PHP 8.3 image builds cleanly, installs cleanly, and then
fails at runtime inside `vendor/composer/platform_check.php` — a confusing
place to land, because nothing earlier complains.

Worth knowing: the root `composer.json` says `"php": "^8.3"`, which looks like
it contradicts this. It doesn't — that is the skeleton's own declared floor,
and it's out of step with what its dependency tree actually requires. The lock
file is the real constraint.

**Alternative considered:** pin an older Laravel or Symfony that still supports
8.3. Rejected — that fights the ecosystem for no benefit on a greenfield build.

### Dropped the anonymous `vendor` volume from the app service

The compose file mounted an anonymous volume over `/var/www/html/vendor`,
which masked the bind-mounted `vendor/` with an empty directory. Docker seeds
such a volume from the image, and the dev Dockerfile target deliberately never
runs `composer install` — so it was seeded from nothing.

That pattern is only sound when something inside the container populates the
volume. The `node_modules` volume on the `vite` service **is** kept, because
that container runs `npm install` on startup and genuinely fills it.

**Trade-off accepted:** slower file I/O for `vendor/` on macOS, in exchange for
one source of truth and an editor that can see the framework source for
autocomplete.

### Database credentials live in docker-compose.yml; `.env` mirrors them

Compose passes `DB_*` into the container as real environment variables, and
Laravel's dotenv loader never overwrites a variable already present in the
environment. So compose wins inside the container no matter what `.env` says.

This was silently true and visibly confusing: `.env` read `sqlite` while the
app was demonstrably talking to MySQL. Rather than leave the two disagreeing,
`.env` and `.env.example` were updated to match compose exactly.

**Decision:** compose remains the source of truth. `.env` is documentation that
agrees with it, not a second place to configure things.

### Vite binds to `0.0.0.0` but advertises `localhost`

The Laravel Vite plugin writes the dev server's host into `public/hot`, and the
browser loads assets from whatever URL it finds there. Started with
`--host 0.0.0.0`, it wrote `http://0.0.0.0:5173`.

`0.0.0.0` is a bind address — an instruction to a server to accept connections
on every interface. It is not a destination a browser can reach. Firefox
refuses it outright, so every asset request failed and the page rendered
completely unstyled, with no error anywhere to explain why.

**Fix:** `server.hmr.host = 'localhost'` in `vite.config.js`. That is the value
the plugin writes to the hot file, so the browser gets a reachable address while
the server still listens on all interfaces for the container networking.

---

## 20 Sep 2026 — data layer

### `access_token` is a `text` column, not `string`

The `encrypted` cast does not store the token. It stores a base64'd JSON
envelope around it — initialisation vector, ciphertext and MAC. A 38-character
Shopify offline token comes out of that at 256 characters. That number is
measured from the seeded row, not estimated.

`varchar(255)` is one character short. Under MySQL's default strict mode that
is an outright `Data too long for column` error at install time; with strict
mode off it truncates silently, and the failure resurfaces much later as a
decryption error on a token that looks perfectly normal in the database.
Nothing about it points back at the schema.

CLAUDE.md specifies the cast but not the column type, so `text` does not
contradict it.

### `discount_type` casts to a `DiscountType` enum, not a string

The column is `enum('percentage','fixed')`. Cast to a plain string, every
consumer compares against a string literal, and a typo — `'percent'`,
`'Percentage'` — is a silent no-match rather than an error.

`TierCalculator` will be the main consumer, and it is the one class CLAUDE.md
asks to be unit-tested. A `match` over two enum cases is exhaustive: add a
third discount type later and PHP raises `UnhandledMatchError` at every call
site that has not been updated, instead of quietly falling through.

**Alternative considered:** string cast plus class constants. Rejected — that
is the same discipline with none of the type checking.

### A `ShopFactory` exists alongside `TierSettingFactory`

Only `TierSettingFactory` was needed, but a tier cannot exist without a shop.
The conventional definition is `'shop_id' => Shop::factory()`, which has to
resolve a shop factory. Without one, every caller creates a shop by hand and
passes the id in — which is the work the factory was supposed to remove.

The seeder needs a shop for the same reason.

### `.env.example` ships empty database values

This narrows the entry above rather than reversing it. `.env` still mirrors
compose exactly; only the example file is blank.

The point of that entry was that `.env` disagreeing with compose was
confusing, and that still holds — `DB_CONNECTION=mysql` stays in both files,
so neither one suggests the wrong driver. What is blank is the host, port,
database name, user and password: the shape of the configuration without the
values.

One exception. `SHOPIFY_SCOPES` carries its real value in both files. It is
not a secret, and an empty scopes line documents nothing about what the app
actually asks for at install time.

**Still open:** these values are already public in `docker-compose.yml`, so
blanking them in the example protects nothing, and a fresh clone cannot copy
the example into a working `.env`. Left blank for now.

---

## 21 Sep 2026 — OAuth install

### Expiring offline tokens, not non-expiring

Install sends `expiring=1` on the token exchange. Shopify's current docs say
new public apps can no longer use non-expiring offline tokens for GraphQL Admin
API calls, and existing public apps lose them on 1 January 2027. Custom apps
are exempt, but the distribution choice for this app isn't final, and the
expiring shape works either way.

What it costs: the access token lives one hour, with a 90-day refresh token.
`shops` gains `access_token_expires_at`, `refresh_token` (encrypted, `text`
for the same envelope reason as `access_token`) and `refresh_token_expires_at`.
All three are nullable, and null means "does not expire" — Shopify only
returns them when it issued an expiring token.

**Consequence carried forward:** install only stores the refresh token.
`ShopifyGraphQLClient` has to refresh before calls, or every call fails an
hour after install.

Also from the docs: offline tokens begin `shpat_` whichever grant produced
them, and refresh tokens begin `shprt_`.

### Redirect-based install, not Shopify-managed install

Shopify now recommends managed install plus token exchange for embedded apps,
which skips the redirect entirely. The authorization code grant still works,
and building it by hand shows the moving parts: nonce, HMAC, code exchange.

**Trade-off accepted:** the nonce lives in the session cookie, so an install
has to start in a normal browser tab. Inside the admin iframe the browser
treats that cookie as third-party and blocks it.

### The shop-domain regex ends in `\z`, not `$`

In PHP, `$` also matches just before a trailing newline, so
`shop.myshopify.com\n` passes a `$`-anchored pattern. `\z` is the true end of
the string. `ShopDomain` also doesn't trim, for the same reason: trimming
would quietly accept exactly that input.

### No Form Requests on the OAuth routes

CLAUDE.md asks for Form Requests for validation. That rule is written for the
JSON API. `/auth` and `/auth/callback` are browser redirects, and a failed
Form Request redirects "back" — meaningless halfway through OAuth. They answer
a plain 400 or 403 instead.

### The OAuth callback skips input trimming and empty-to-null

Laravel's global `TrimStrings` and `ConvertEmptyStringsToNull` rewrite query
parameters, not just form fields. Shopify's HMAC covers the parameters exactly
as sent, so any rewrite makes a genuine callback fail verification. Both are
skipped for `auth/callback` only.

**Partly confirmed:** a real callback from the dev store passed verification
on 21 Sep 2026. That proves the sort order, the `key=value&` format and the
secret. It does not prove the encoding: the signed message is built with
`http_build_query`, the URL-encoded form, but no value in that callback
needed encoding. `host` arrived as plain base64 with no `=`, `+` or `/`.
Base64 can contain all three, so a shop whose `host` does is still the
untested case.

### Troubleshooting: a leftover dev preview overrides every released version

Install failed with Shopify's `The redirect_uri is not whitelisted`, even
though the Dev Dashboard's active version listed the exact callback URL and
the Client ID matched. Two more releases changed nothing.

The cause was a dev preview attached to the dev store — the store admin showed
"dev previews (1)" in the bottom corner. `shopify app dev` creates one: a
temporary copy of the app's settings, usually pointing at an old tunnel, that
the store uses instead of the active version. Shopify's CLI docs say the
clean-up command "restores the app's active version to the selected
development store".

**Fix:** `npx @shopify/cli@latest app dev clean --client-id=<id>
--store=<store>.myshopify.com`. Install worked on the next try.

**Lesson:** if dashboard changes have no effect on one store, check for a dev
preview before changing anything else. And don't run `shopify app dev` against
this app — it recreates the preview.

### "Use legacy install flow" is on — untested whether it's needed

It was ticked while chasing the error above, before the real cause was found.
Shopify's docs describe the legacy flow as the one that "requests scopes
through a URL parameter during the OAuth flow", which is what this code does,
so it is the setting that matches. But install was never tried with it off
after the dev preview was cleared.

**Still open:** untick it in a new version, reinstall, and record the result
here.

---

## 22 Sep 2026 — session-token middleware

### Tests pin the database with `<server>`, not `<env>`

`phpunit.xml` switched tests to SQLite with `<env>` lines. Inside the `app`
container those did nothing. Compose sets `DB_CONNECTION=mysql` as a real
environment variable, PHP copies it into `$_SERVER`, and Laravel's env reader
checks `$_SERVER` before `$_ENV`. PHPUnit's `<env>` only writes `$_ENV` and
`putenv()` — `force="true"` included — so Laravel kept resolving `mysql`.

Confirmed in the container: overriding the way `<env>` does left
`config('database.default')` at `mysql`; overriding `$_SERVER` changed it to
`sqlite`.

It had done no damage only because no test touched the database yet. The
first `RefreshDatabase` test would have run `migrate:fresh` against
`wholesale_tiers` and dropped the installed shop along with its tokens.

**Fix:** `<server>` for `DB_CONNECTION` and `DB_DATABASE`, which PHPUnit
writes straight into `$_SERVER`. Both are needed: with only the driver
switched, SQLite would open a file called `wholesale_tiers`.

**Guard:** `Tests\TestCase` checks the connection Laravel actually resolved
before any database trait runs, and stops the run if it isn't in-memory
SQLite. That also catches a cached config, which ignores `phpunit.xml`
entirely.

Same family as the 19 Sep entry: Compose's environment beats files that look
like they configure things.

### JWT checks live in a pure `SessionTokenVerifier`

The middleware needs the database to find the shop, so it can't be
unit-tested on its own. The token checks don't. They sit in their own class
with no framework calls, like `OAuthHmacVerifier`, and are unit-tested with
tokens signed inside the test. The middleware stays thin: read the header,
call the verifier, look up the shop.

Test secrets are over 32 bytes. php-jwt v7 rejects shorter HS256 keys, and a
rejected key would make every "token is rejected" test pass for the wrong
reason — so each of those tests also checks the exception message.

### The shop goes on `$request->attributes`, not `$request->shop`

CLAUDE.md describes the middleware as attaching `$request->shop`. In Laravel
that magic property is `Request::__get`, which reads the query string and body
first. On `/api/customers?shop=other-store.myshopify.com` it would return the
other store — exactly the cross-shop hole that taking the shop from `dest`
exists to close.

`attributes` is server-side only. A feature test sends a valid token for one
shop with `?shop=` naming another, and checks the first shop is the one
resolved.

### `aud` is checked even though the signature already is

Each app has its own secret, so another app's token fails the signature check
anyway. `aud` is still checked: the signature proves who signed the token,
`aud` says who it was for, and Shopify lists it as required. Without it,
correctness would rest on the secret never being used anywhere else.

The verifier also requires `exp` and `nbf` to be present — php-jwt only checks
them when they exist — and checks that `iss` and `dest` name the same shop,
which Shopify's docs also require.

### No retry header when the shop isn't installed

Token failures answer 401 with `X-Shopify-Retry-Invalid-Session-Request: 1`,
which asks App Bridge to fetch a fresh token and retry. When the token is valid
but the shop has no row, or has uninstalled, the header is left off: a fresh
token can't fix that, so the retry would fail the same way.

Every 401 has the same body whichever check failed, so a caller learns nothing
about which one to work around. The reason goes to the log at `info`.

**Still open:** the retry header wasn't on the Shopify docs pages checked for
this (ID tokens, App Bridge resource fetching). It's sent because it's
harmless. Whether App Bridge really retries once hasn't been seen yet — check
the Network tab once the React shell exists.

### Leeway is 5 seconds

`JWT::$leeway` absorbs clock difference between this server and Shopify. The
token only lives 60 seconds, so a large leeway would noticeably stretch a
stolen token's life. It's a static, so it applies process-wide; the verifier
is the only code that decodes JWTs.

---

## 22 Sep 2026 — GraphQL client and the customers endpoint

### Access tokens are refreshed before each call, one shop at a time

The token saved at install lives an hour, so `ShopifyGraphQLClient` asks
`OAuthService::freshAccessToken()` for it on every attempt. With more than a
minute left, the stored token comes straight back. Otherwise it's refreshed
with `grant_type=refresh_token`. Each refresh also returns a new refresh
token, and both are saved — the old refresh token stops working once the new
one is used.

Shopify's docs warn: refresh one store at a time. Two requests refreshing
together each retire the other's result, so one of them ends up holding a
token that's already dead. The refresh therefore runs under a per-shop cache
lock, and **the row is reloaded inside the lock**. If another request
refreshed while this one waited, the reload shows a fresh token and nothing
more happens. Without the reload, the lock would only make the two refreshes
take turns; both would still run.

A test covers exactly that case: a stale model, a fresh row, and no HTTP
request allowed. Removing the reload line makes the test fail — checked by
doing it.

Smaller details:

- The lock lasts 30 seconds and the refresh request times out after 10, so
  the lock can't expire while a refresh is still in flight.
- A 401 on refresh is final: the refresh token is dead and the merchant must
  reinstall. It isn't retried.
- The stored shop domain goes back through `ShopDomain` before refreshing,
  although install already checked it. The app-wide client secret is about to
  be posted to that host.
- `install()` and the refresh share one method that turns Shopify's token
  response into columns, so the two can't drift apart.

### The throttle retry is written by hand

Shopify reports GraphQL throttling as HTTP 429 or — more often — as a normal
200 with `THROTTLED` in the `errors` array. Laravel's `Http::retry()` only
reacts to failed status codes, so it would never see the second kind.

The client retries twice, waiting 1 second and then 2, through Laravel's
`Sleep` so tests can fake the waits instead of sitting through them. A
development store almost never throttles, so a faked test is the only proof
the loop works.

**Better, not built:** the response's `extensions.cost.throttleStatus` says
how many points are left and how fast they come back, so a client could wait
exactly as long as it needs to.

### Only the cost block is logged

Each Admin API response logs the shop, the status and `extensions.cost` at
debug level. Never the variables, never the body: customer names and emails
are protected customer data and have no business in a log file.

### `tier` is limited to letters, digits, `-` and `_`

The tier is pasted into Shopify's search syntax as `tag:<tier>`. Unchecked,
`?tier=x OR tag:y` would change what the search matches. It can't reach
another shop — the token pins the shop — but it's still user input going into
a query language. The regex ends in `\z`, like `ShopDomain`'s.

**Cost:** a merchant tag with a space, such as `Wholesale Gold`, can't be
filtered on. Whatever lets merchants define tiers later needs the same rule,
or proper quoting.

### No tier sends an empty search string

The verified query declares `$query: String!` — required — so "all customers"
can't just leave it out; it sends `""`.

**Confirmed on the dev store:** `""` returned 8 customers with no further
page, and `tag:wholesale-gold` returned exactly Priya Sharma and Rohan Mehta.
Cursor pagination too: at 3 per page, following `endCursor` gave pages of 3,
3 and 2 — all 8 customers, none repeated.

### First live refresh, and the token prefix was wrong

The checkpoint's first call found the access token a day past its expiry and
refreshed it at 03:52 UTC on 22 Sep. Afterwards the row held a new access
token expiring an hour later, and a refresh-token expiry moved to 90 days from
the refresh (21 Dec, up from 20 Dec). Both customer queries then succeeded
with the new token. Measured by decrypting the row, not assumed.

**Correction to the 21 Sep entry:** it says offline tokens begin `shpat_`
whichever grant produced them. That came from the docs. The token that came
back from the refresh begins `shpua_`. Nothing in the code checks the prefix,
so nothing broke — but the claim was wrong, at least for refreshed tokens.

Also worth knowing: the token columns are encrypted with a fresh random IV on
every save, so a database viewer shows the same kind of noise before and
after a refresh. The only way to see that a token changed is to decrypt it.

**Then verified in the browser too**, the same morning. From the app's frame
in the admin, `fetch('/api/customers?tier=wholesale-gold')` — with no header
added by hand — returned Priya Sharma and Rohan Mehta. So App Bridge attaches
the ID token by itself, and the middleware accepts a real Shopify token:
signature, `aud`, `iss`/`dest` and the store lookup all passed. The server log
has the matching cost line and no rejection.

Getting there needed the Console pointed at the app's frame, not the admin
page around it. In Firefox that's the frame picker at the bottom right of the
Console.

**Still not seen:** App Bridge retrying after a 401 with the retry header
(see the session-token entry above).

### The entry page only loads App Bridge

`/` now serves a placeholder view with the two tags from Shopify's App Bridge
migration guide: `<meta name="shopify-api-key">` and the CDN script. It exists
so the embedded app can issue ID tokens for `/api` calls; the React shell
replaces its body later.

The client ID in that page is public by design. The secret and the access
tokens never reach a view.

### Confirmed: no session or cookies on `/api`

`route:list -v` only names the `api` group, which proves nothing. Expanding it
through the router shows `SubstituteBindings` and `VerifyShopifySessionToken`
— no `StartSession`, no `PreventRequestForgery`. And `curl` against
`/api/customers` gets no `Set-Cookie` header, while `/` gets two.
