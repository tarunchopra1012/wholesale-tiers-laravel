# wholesale-tiers

An embedded Shopify admin app that lets a merchant assign wholesale discount
tiers to customers by tag and preview the resulting prices.

> **Status: early.** The development environment, the Laravel scaffold, the
> database layer, the OAuth install flow, session-token verification and the
> first Admin API endpoint (`/api/customers`) are in place. There is no UI
> yet — the admin page only loads App Bridge. The roadmap below marks
> honestly what exists and what doesn't.

## The problem it solves

Shopify's native B2B features — companies, price lists, catalogs — are
**Shopify Plus only**. Every merchant below Plus who sells wholesale fakes it
with customer tags: they tag one café `wholesale-gold`, another
`wholesale-silver`, and then have no built-in way to charge those customers
different prices.

This app closes that gap. It is a deliberately small version of what the
commercial wholesale-pricing apps do.

What a merchant can do with it:

1. See all customers grouped by wholesale tier
2. Define what each tier means — `wholesale-gold` is 20% off,
   `wholesale-silver` is 10% off
3. Preview the resulting price for any product, per tier

This is not a storefront, a theme, or a headless site. Nobody shopping ever
sees it. It is a tool inside the merchant's admin.

## Stack

| Layer             | Choice                          | Version     |
| ----------------- | ------------------------------- | ----------- |
| Backend           | Laravel                         | 13.x        |
| Language          | PHP                             | 8.4         |
| Frontend          | React + Vite                    | 18.x        |
| UI                | Shopify Polaris                 | latest      |
| Embedding         | App Bridge (CDN script)         | latest      |
| Database          | MySQL                           | 8.0         |
| Shopify Admin API | GraphQL                         | 2026-07     |
| Runtime           | Docker Compose                  | —           |

## Architecture

Four rules shape the whole design:

1. **The Shopify access token never reaches the browser.** Every Admin API
   call originates in Laravel. There is no Shopify GraphQL in the React code.
2. **React talks to Laravel over plain REST JSON.** The only GraphQL in this
   codebase is the outbound query string Laravel sends to Shopify. No GraphQL
   server, no Apollo.
3. **Every API request carries a session ID token.** App Bridge attaches
   `Authorization: Bearer <jwt>` to same-origin `fetch` calls, and Laravel
   middleware verifies it on every request. No sessions, no cookies, no CSRF
   tokens on `/api/*`.
4. **One shop is one row.** The offline access token lives there, encrypted,
   and the shop is resolved from the ID token's `dest` claim — never from a
   query parameter the client controls.

Request flow:

```
Browser (React in the Shopify admin iframe)
  │  fetch /api/customers, Authorization: Bearer <ID token, ~60s TTL>
  ▼
Laravel  ── VerifyShopifySessionToken middleware
  │        verifies signature, aud and exp; resolves the shop from dest
  ▼
ShopifyGraphQLClient
  │  POST https://{shop}/admin/api/2026-07/graphql.json
  │  X-Shopify-Access-Token: {shop.access_token}
  ▼
Shopify Admin API
```

### Data model

```
shops
  id, shop_domain (unique), access_token (encrypted cast),
  access_token_expires_at, refresh_token (encrypted cast),
  refresh_token_expires_at, scopes, installed_at,
  uninstalled_at (nullable), timestamps

tier_settings
  id, shop_id (fk, cascade), tag, discount_type enum(percentage,fixed),
  discount_value decimal(10,2), timestamps
  unique(shop_id, tag)
```

Two tables is the right size. There is deliberately **no `customers` table** —
customer data lives in Shopify, and caching it creates a sync problem this app
does not need.

Two details the shorthand above hides. `access_token` is a `text` column, not
`varchar(255)` — the `encrypted` cast stores a base64'd envelope of iv,
ciphertext and MAC, which takes a 38-character Shopify token to 256
characters, one past the limit. And `discount_type` casts to a `DiscountType`
enum rather than a bare string, so `TierCalculator` will match on cases
instead of string literals.

## Running it locally

Requires Docker Desktop. Nothing else — no PHP, Node or MySQL on your machine.

`.env` is not committed, so copy the example first:

```bash
cp .env.example .env
```

Its blank `DB_*` values are fine — compose passes the real ones into the
container. Then bring the stack up:

```bash
docker compose up -d --build
```

Generate the app key and create the tables:

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

`--seed` is optional. It gives you one development shop with two tiers,
`wholesale-gold` at 20% off and `wholesale-silver` at 10% off.

| Service      | URL / Port              |
| ------------ | ----------------------- |
| App          | http://localhost:8000   |
| Vite dev     | http://localhost:5173   |
| MySQL        | 127.0.0.1:3307          |

Run artisan inside the container, not on the host:

```bash
docker compose exec app php artisan <command>
```

### Installing on a development store

Shopify has to reach the app over HTTPS, so expose it with a tunnel:

```bash
cloudflared tunnel --url http://localhost:8000
```

Then:

1. In `.env`, set `SHOPIFY_API_KEY` (the Client ID) and `SHOPIFY_API_SECRET`
   (the Client secret) from the app's **App settings** page in the Dev
   Dashboard, and `SHOPIFY_APP_URL` to the tunnel URL.
2. In the Dev Dashboard, open **Versions → Create version**. Set the App URL
   to the tunnel URL, add `<tunnel url>/auth/callback` under Allowed
   redirection URL(s), keep Scopes the same as `SHOPIFY_SCOPES`, and click
   **Release**. A released version can't be edited; every change is a new
   version.
3. Visit `<tunnel url>/auth?shop=<your-store>.myshopify.com` in a normal
   browser tab — not inside the Shopify admin — and approve the install.

A quick tunnel gets a new URL every time it starts, so steps 1 and 2 repeat
on every restart.

**If Shopify says `The redirect_uri is not whitelisted` when the URL is
correct,** look for "dev previews" in the store admin's bottom corner. A
leftover preview from `shopify app dev` overrides the released version for
that store. Clear it with:

```bash
npx @shopify/cli@latest app dev clean --client-id=<client id> --store=<your-store>.myshopify.com
```

### Setup notes worth knowing

A few things in this stack are easy to get wrong, and cost real time to
diagnose:

- **PHP must be 8.4 or newer.** Laravel 13 pulls in Symfony 8, which declares
  `php: >=8.4.1`. A PHP 8.3 base image installs fine and then fails at runtime
  in `vendor/composer/platform_check.php`, which is a confusing place to land.
- **The database settings come from `docker-compose.yml`, not `.env`.**
  Compose passes `DB_*` in as real environment variables, and Laravel's dotenv
  loader never overwrites a variable that already exists in the environment.
  Both files are kept in sync so this can't surprise you.
- **`APP_KEY` has to exist before anything writes a shop row.**
  `shops.access_token` uses Laravel's `encrypted` cast, so a missing key fails
  with `No application encryption key has been specified` — at seed time
  rather than at migrate time, which makes it look like a seeder bug.
- **The Vite dev server advertises `localhost`, not `0.0.0.0`.** It binds to
  all interfaces inside the container, but `public/hot` has to contain an
  address a browser can actually reach. `0.0.0.0` is not one, and the symptom
  is a completely unstyled page with no error.

## Roadmap

Built:

- [x] Dockerised dev environment — php-fpm, nginx, MySQL 8, Vite
- [x] Laravel 13 scaffold on PHP 8.4, MySQL wired up
- [x] Data layer — `shops` and `tier_settings` migrations, models, factories
  and a development seeder
- [x] OAuth install flow — shop-domain check, HMAC and one-time nonce
  verification, expiring offline token stored encrypted
- [x] Session-token verification middleware — ID token checked on every
  `/api` request, shop taken from the token's `dest` claim
- [x] `ShopifyGraphQLClient` with token refresh (one shop at a time, under a
  lock) and throttle retries, and the first `/api/customers` endpoint

Not built yet:

- [ ] React + Polaris + App Bridge shell
- [ ] Customers page — `IndexTable` with tier badges and filtering
- [ ] Settings page — discount percentage per tier
- [ ] Price preview — base price against each tier's price
- [ ] Containerised deployment behind a real HTTPS domain

## Deliberately out of scope

The exclusions are as considered as the build:

| Excluded                                 | Why                                                                                                                       |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Shopify Function for checkout discounts  | In production the discount has to execute at checkout, on Shopify's infrastructure — an app server cannot sit in that path. It means Rust/WASM and its own toolchain. This is the correct next piece, not an oversight. |
| Theme app extension                      | A display concern on the storefront, separate from the pricing engine                                                     |
| Webhooks (`app/uninstalled`, `customers/update`) | Needed for production hygiene — orphaned records on uninstall — but adds infrastructure without changing what the app demonstrates |
| Billing API, GDPR webhooks, multi-store  | These only matter for public App Store distribution                                                                       |
| Broad test coverage                      | Tests cover only the places where a bug would be a security hole or a silent failure: the OAuth HMAC check, session-token verification, the middleware's shop resolution, token refresh and the throttle retry. `TierCalculator` will get unit tests when it exists. Controllers, views and the happy path through Shopify are checked by hand against a development store. |

## License

MIT
