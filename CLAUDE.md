# wholesale-tiers

An embedded Shopify admin app that lets a merchant assign wholesale discount
tiers to customers by tag and preview the resulting prices.

Context: Shopify's native B2B (companies, price lists, catalogs) is Plus-only.
Merchants below Plus do wholesale with customer tags and have no way to price
against them. This app closes that gap.

## Stack

| Layer             | Choice                                       | Version     |
| ----------------- | -------------------------------------------- | ----------- |
| Backend           | Laravel                                      | 13.x        |
| Frontend          | React + Vite                                 | 18.x        |
| UI                | Shopify Polaris                              | latest      |
| Embedding         | App Bridge (CDN script)                      | latest      |
| Database          | MySQL                                        | 8.0         |
| Shopify Admin API | GraphQL                                      | **2026-07** |
| Checkout logic    | Shopify Function (JavaScript → WebAssembly)  |             |
| Function tooling  | Shopify CLI, on the host under Node 22+      | 4.x         |
| Runtime           | Docker Compose (dev), container image (prod) |             |

## Architecture rules — do not violate these

1. **The Shopify access token NEVER reaches the browser.** All Admin API calls
   originate in Laravel. If you find yourself writing a Shopify GraphQL query in
   React, stop — it belongs in a Laravel service. The checkout Function has no
   token either and makes no API calls: Laravel feeds it through a metafield.

2. **React talks to Laravel over plain REST JSON.** We are not building a
   GraphQL server. Do not add Lighthouse, do not add Apollo Server. The only
   GraphQL in this codebase is the outbound query string Laravel sends to
   Shopify, and the Function's input query in `extensions/`, which Shopify
   runs, not us.

3. **Every API request from the frontend carries an ID token.** App Bridge
   attaches `Authorization: Bearer <jwt>` automatically to same-origin `fetch`
   calls. Laravel middleware verifies it on every request. There are no
   sessions, no cookies, no CSRF tokens on `/api/*`.

4. **One shop = one row in `shops`.** The offline access token lives there,
   encrypted. Resolve the shop from the ID token's `dest` claim, never from a
   query parameter the client controls.

## Request flow

```
Browser (React in admin iframe)
  │  fetch /api/customers, Authorization: Bearer <ID token, ~60s TTL>
  ▼
Laravel  ── VerifyShopifySessionToken middleware
  │        verifies signature (app secret), aud, exp; resolves shop from dest
  │        attaches it as $request->attributes->get('shop')
  │        (never $request->shop — that magic property reads ?shop= input)
  ▼
ShopifyGraphQLClient
  │  POST https://{shop}/admin/api/2026-07/graphql.json
  │  X-Shopify-Access-Token: {shop.access_token}
  ▼
Shopify Admin API
```

## Directory conventions

```
app/
  Http/
    Controllers/Api/     CustomerController, TierSettingController, PreviewController,
                         CheckoutStatusController
    Middleware/          VerifyShopifySessionToken
    Resources/           CustomerResource, TierSettingResource, CheckoutStatusResource
  Models/                Shop, TierSetting
  Services/Shopify/
    ShopifyGraphQLClient.php    thin HTTP wrapper, retries on 429
    CustomerQuery.php           query strings + response mapping
    ProductQuery.php
    TierDiscountSync.php        creates the tier discount once, then writes its metafield;
                                status() reports whether it is still active
  Support/
    TierCalculator.php          pure pricing logic, no I/O, unit-testable
    TierDiscountConfig.php      pure: a shop's tiers → the JSON the Function reads
extensions/
  wholesale-tier-discount/      the checkout Function; runs on Shopify, not here.
                                Own package.json, built and uploaded by Shopify CLI
resources/js/
  main.jsx               entry; not app.jsx — macOS can't hold it beside App.jsx
  App.jsx
  pages/                 Customers.jsx, Settings.jsx, Preview.jsx
  lib/api.js             fetch wrapper; App Bridge handles the auth header
routes/
  api.php                all /api/* routes, behind the session-token middleware
  web.php                OAuth install + callback, and the SPA catch-all
shopify.app.toml         Shopify CLI's description of the app; `deploy` releases from it
```

## Database

```
shops
  id, shop_domain (unique), access_token (encrypted cast), scopes,
  installed_at, uninstalled_at nullable,
  tier_discount_id nullable, tier_discount_synced_at nullable, timestamps

tier_settings
  id, shop_id (fk, cascade), tag, name nullable, discount_type enum(percentage,fixed),
  discount_value decimal(10,2), badge_tone string default 'info', timestamps
  unique(shop_id, tag)
```

Do not create a `customers` table. Customer data lives in Shopify; caching it
creates a sync problem we do not need.

## Shopify specifics that are easy to get wrong

- **API version is pinned to `2026-07`.** Do not use a version from memory.
- **Customer fields moved.** It is `defaultEmailAddress { emailAddress }` and
  `defaultPhoneNumber { phoneNumber }`, NOT a flat `email` / `phone` field.
- **Protected customer data is a second gate beyond scopes.** `read_customers`
  alone returns `ACCESS_DENIED`; the app must also declare data use in the
  Partner Dashboard. This is already configured — don't try to fix it in code.
- **Query cost, not request count.** Budget is 2000 points, restoring at
  100/sec. A nested customer query costs ~5. Handle HTTP 429 with backoff.
- **Pagination is cursor-based.** Use `pageInfo { hasNextPage endCursor }` and
  `after:`, never numeric offsets.
- **The app renders in an iframe.** Never send `X-Frame-Options`.
- **Scopes are `read_customers,read_products,write_discounts`.** A new scope
  only takes effect after the store approves it again through `/auth`.
- **Laravel and the Function share one thing: a JSON metafield** on the tier
  discount, namespace `$app:wholesale-tiers`, key `function-configuration`:
  `{"tags": [...], "tiers": [{"tag", "name", "type", "value"}]}`. `value` is a
  decimal string. `name` is what checkout shows; Laravel writes the tag there
  when a tier has no name. Laravel always writes the whole list, never a
  single change.
- **Never run `shopify app dev`.** It recreates the dev preview that broke
  installs (DECISIONS.md, 21 Sep).
- **`shopify app deploy` releases a version built from `shopify.app.toml`.**
  A stale tunnel URL in the toml overwrites the right one. Check the URLs
  before every deploy and the Dev Dashboard's newest version after.

### A query that is verified to work

```graphql
query TieredCustomers($query: String!, $first: Int!, $after: String) {
  customers(first: $first, query: $query, after: $after) {
    edges {
      cursor
      node {
        id
        firstName
        lastName
        tags
        defaultEmailAddress {
          emailAddress
        }
        defaultAddress {
          formattedArea
        }
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}
```

With `query: "tag:wholesale-gold"` this returns customers with that tag.
Use it as the shape reference for other queries.

## Code standards

- PHP 8.3+ (Laravel 13 minimum), strict types, constructor property promotion, readonly where it fits.
- Typed properties and return types everywhere. No `mixed` unless unavoidable.
- Form Requests for validation, API Resources for serialisation. No arrays
  returned straight from controllers.
- Business logic in Services or Support, not in controllers. Controllers wire
  input to a service and return a Resource.
- `TierCalculator` must be pure: takes a price and a tier, returns a price. No
  database, no HTTP. It is the one class worth unit-testing.
- React: function components and hooks. Polaris components only — no custom CSS
  beyond layout. No Redux; `useState` and `useEffect` are enough for three pages.

## Things NOT to build

Out of scope for this build. If a task seems to need one of these, stop and ask:

- Theme app extensions
- Webhooks
- Billing API, GDPR webhooks, multi-store support
- A test suite beyond `TierCalculator`, `TierDiscountConfig`,
  `TierDiscountSync` and the Function's fixtures

## Working style

- Before writing Shopify API code, check the query against the running GraphiQL
  instance rather than relying on memory of the schema.
- Prefer a plan before multi-file changes.
- Small commits: one concern each.
