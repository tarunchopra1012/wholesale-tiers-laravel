# How the code flows: every feature, function by function

_Written 8 Oct 2026 from the code on `main`. Functions are named, not given
line numbers, so this stays true as the files change._

The other documents explain _why_ each part works the way it does:
[how-install-auth-works.md](how-install-auth-works.md),
[how-api-auth-works.md](how-api-auth-works.md) and
[how-checkout-discount-works.md](how-checkout-discount-works.md).
This one is a map: for each feature, which function calls which, and what
each one does.

---

## 1. How to read this

- Part one (sections 2 to 11) follows each flow through the server.
- Part two (sections 12 to 17) covers each frontend file from the inside.
- In the call trees, indentation means "calls".
- Two chains repeat inside almost every flow. They are written out once, as
  **A** (every `/api` request) and **B** (every call to Shopify), and
  referenced by letter afterwards.

Where the files are:

| Layer       | Files                                                                                                                                                                                                                                                                                                                                          |
| ----------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Routes      | [routes/web.php](../routes/web.php), [routes/api.php](../routes/api.php), wiring in [bootstrap/app.php](../bootstrap/app.php)                                                                                                                                                                                                                  |
| Controllers | [app/Http/Controllers/Api/](../app/Http/Controllers/Api), [ShopifyOAuthController.php](../app/Http/Controllers/Auth/ShopifyOAuthController.php)                                                                                                                                                                                                |
| Services    | [OAuthService.php](../app/Services/Shopify/OAuthService.php), [ShopifyGraphQLClient.php](../app/Services/Shopify/ShopifyGraphQLClient.php), [TierDiscountSync.php](../app/Services/Shopify/TierDiscountSync.php), [CustomerQuery.php](../app/Services/Shopify/CustomerQuery.php), [ProductQuery.php](../app/Services/Shopify/ProductQuery.php) |
| Pure logic  | [TierCalculator.php](../app/Support/TierCalculator.php), [TierDiscountConfig.php](../app/Support/TierDiscountConfig.php)                                                                                                                                                                                                                       |
| Function    | [cart_lines_discounts_generate_run.js](../extensions/wholesale-tier-discount/src/cart_lines_discounts_generate_run.js)                                                                                                                                                                                                                         |
| Frontend    | [lib/api.js](../resources/js/lib/api.js), [Customers.jsx](../resources/js/pages/Customers.jsx), [Settings.jsx](../resources/js/pages/Settings.jsx), [Preview.jsx](../resources/js/pages/Preview.jsx)                                                                                                                                           |

---

# Part one: the flows through the server

## 2. Chain A: every `/api` request (the way in)

Purpose: prove which shop the browser request belongs to before any
controller runs.

```
Page component (Customers.jsx / Settings.jsx / Preview.jsx)
└─ api(path, options)                              lib/api.js
   └─ fetch('/api' + path)                         App Bridge adds Authorization: Bearer <ID token>
      └─ routes/api.php                            middleware attached to the group in bootstrap/app.php
         └─ VerifyShopifySessionToken::handle()
            ├─ $request->bearerToken()
            ├─ SessionTokenVerifier::verify($jwt)
            │  ├─ JWT::decode()                    signature, exp, nbf
            │  ├─ aud === api key
            │  └─ ShopDomain::tryFrom(dest host)   iss must name the same shop
            ├─ Shop::where('shop_domain', …)->first()
            ├─ reject() → 401                      on any failure
            └─ $request->attributes->set('shop', $shop) → the controller
```

| #   | Function                               | What it does                                                                                                                                               |
| --- | -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `api()` in `lib/api.js`                | The one place the frontend calls `fetch`. Adds JSON headers, and turns a failed response into an `Error` carrying `status`, `errors` and `reauthorizeUrl`. |
| 2   | App Bridge's wrapped `fetch`           | Shopify's script. Gets a fresh 60-second ID token from the admin and adds `Authorization: Bearer <token>`.                                                 |
| 3   | `routes/api.php` + `bootstrap/app.php` | Matches the URL to a controller. The middleware is attached to the whole `api` group, so no route can skip it.                                             |
| 4   | `VerifyShopifySessionToken::handle()`  | Reads the bearer token and coordinates the checks below.                                                                                                   |
| 5   | `SessionTokenVerifier::verify()`       | Checks the signature with our app secret, the `exp`/`nbf` window, that `aud` is our API key, and that `iss` and `dest` name the same shop.                 |
| 6   | `ShopDomain::tryFrom()`                | Accepts only a valid `*.myshopify.com` domain, so nothing else can be used as a host later.                                                                |
| 7   | `Shop::where(...)->first()`            | Loads the shop's row. No row, or an uninstalled one, is rejected.                                                                                          |
| 8   | `reject()`                             | Answers every failure with the same 401, logs the real reason, and adds the retry header when a fresh token could help.                                    |
| 9   | `$request->attributes->set('shop', …)` | Hands the verified shop to the controller. Controllers never read the shop from the URL.                                                                   |

## 3. Chain B: every call to Shopify (the way out)

Purpose: send one GraphQL query to the shop's Admin API with a working access
token, and translate every kind of failure into a specific exception.

```
ShopifyGraphQLClient::query($query, $variables)
├─ send()
│  ├─ OAuthService::freshAccessToken($shop)
│  │  ├─ storedToken($shop, 'access_token')              null if empty or undecryptable
│  │  ├─ expiresSoon($shop)                              within 60 seconds
│  │  └─ Cache::lock(…)->block(…)                        only when unreadable or expiring
│  │     ├─ $shop->refresh()
│  │     └─ refreshAccessToken($shop)
│  │        ├─ storedToken($shop, 'refresh_token')       null → ReauthorizationRequiredException
│  │        ├─ Http::post(/admin/oauth/access_token)     401 → ReauthorizationRequiredException
│  │        ├─ tokenColumns($response)
│  │        ├─ forgetUnreadableTokens($shop)
│  │        └─ $shop->update($columns)
│  └─ Http::post(/admin/api/2026-07/graphql.json)        X-Shopify-Access-Token header
├─ isThrottled($response)                                retry after 1s, then 2s
├─ 401 → ReauthorizationRequiredException(reauthorizeUrl())
├─ failed / GraphQL errors → ShopifyApiException
└─ return $response->json('data')
```

`OAuthService` is built by the container (bindings in
`AppServiceProvider::register()`); the controllers create
`new ShopifyGraphQLClient($shop, $oauth)` themselves.

| #   | Function                           | What it does                                                                                                                                                |
| --- | ---------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `ShopifyGraphQLClient::query()`    | The public entry. Sends the query, retries if throttled, checks the answer, returns the `data` block.                                                       |
| 2   | `send()`                           | Makes one HTTP attempt. Asks for a fresh token each attempt, so a retry never goes out with a token that expired during the wait. Logs only the query cost. |
| 3   | `OAuthService::freshAccessToken()` | Returns a usable access token. Usually just reads it from the row; refreshes first when needed.                                                             |
| 4   | `storedToken()`                    | Reads a token column safely. Returns null when it is empty or cannot be decrypted, instead of throwing.                                                     |
| 5   | `expiresSoon()`                    | True when the token expires within 60 seconds.                                                                                                              |
| 6   | `Cache::lock(...)->block()`        | Lets only one request per shop refresh at a time, then reloads the row in case another request already did it.                                              |
| 7   | `refreshAccessToken()`             | Posts the refresh token to Shopify. No readable refresh token, or a 401 from Shopify, means only the merchant can fix it.                                   |
| 8   | `tokenColumns()`                   | Turns Shopify's token response into the four columns to save (both tokens and both expiry times).                                                           |
| 9   | `forgetUnreadableTokens()`         | Blanks undecryptable old values in memory so saving the new ones does not throw.                                                                            |
| 10  | `isThrottled()`                    | Detects throttling in both forms: HTTP 429, or a 200 with a `THROTTLED` error. Triggers a retry after 1s, then 2s.                                          |
| 11  | Result checks in `query()`         | 401 becomes `ReauthorizationRequiredException`; other failures and GraphQL errors become `ShopifyApiException`.                                             |

Any other failure at the token endpoint (a 5xx, or Shopify unreachable) is an
`OAuthException`: it says nothing about the refresh token, so the next request
simply tries again.

## 4. Page load (the iframe)

Purpose: deliver the empty React shell. No authentication happens here.

```
GET /?embedded=1&shop=…&id_token=…
└─ routes/web.php   Route::view('/{path?}', 'app')        no controller, no auth check
   └─ resources/views/app.blade.php
      ├─ <script src="…/app-bridge.js">                   wraps window.fetch
      └─ @vite('resources/js/main.jsx')
         └─ createRoot(...).render(<App />)               main.jsx
            └─ App()                                      App.jsx   <Routes>: / , /settings , /preview
```

| #   | Function                                             | What it does                                                                                                                        |
| --- | ---------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `Route::view('/{path?}', 'app')` in `routes/web.php` | Serves the same page for `/`, `/settings` and `/preview`, so a reload on any of them works. Excludes `/api`, `/docs`, `/telescope`. |
| 2   | `app.blade.php`                                      | Outputs the API key in a meta tag, loads App Bridge first, then the Vite bundle.                                                    |
| 3   | `main.jsx`                                           | Imports the Polaris CSS and mounts `<App />` into `<div id="root">`.                                                                |
| 4   | `App()`                                              | Shows a warning if not inside the admin; otherwise maps each path to its page component.                                            |

Shopify adds `id_token`, `hmac`, `shop`, `host` and `timestamp` to this URL.
This route ignores all of them: the page holds no shop data. Because `/` is in
the `web` group, Laravel also starts a database session here; the `/api`
routes have none.

## 5. OAuth install

Purpose: get the merchant's permission and store the shop's tokens. Runs on
first install and again on Reconnect.

```
GET /auth?shop=…                                   routes/web.php
└─ ShopifyOAuthController::install()
   ├─ ShopDomain::tryFrom($request->query('shop'))        invalid → 400
   ├─ random_bytes(32) → session()->put('shopify_oauth', {state, shop})
   └─ OAuthService::authorizeUrl($shop, $state)
      └─ redirectUri()                                    → redirect to Shopify

GET /auth/callback?code=…&hmac=…&state=…&shop=…
└─ ShopifyOAuthController::callback()
   ├─ ShopDomain::tryFrom()                               invalid → 400
   ├─ OAuthHmacVerifier::verify($request->query())        fails → 403
   ├─ session()->pull('shopify_oauth')                    state or shop mismatch → 403
   ├─ OAuthService::install($shop, $code)
   │  ├─ Http::post(/admin/oauth/access_token)            code + client secret
   │  ├─ tokenColumns($response)
   │  ├─ missingScopes($granted)                          any missing → OAuthException → 403
   │  ├─ Shop::firstOrNew(['shop_domain' => …])
   │  ├─ forgetUnreadableTokens($row)                     only for an existing row
   │  └─ $row->fill([...])->save()                        tokens encrypted by the model's casts
   └─ OAuthService::adminAppUrl($shop)                    → redirect into the admin
```

| #   | Function                             | What it does                                                                                                                         |
| --- | ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | `ShopifyOAuthController::install()`  | Validates the shop domain, creates a random nonce, stores it with the shop in the session, and redirects to Shopify.                 |
| 2   | `OAuthService::authorizeUrl()`       | Builds Shopify's authorize URL with our client ID, scopes, redirect URI and the nonce.                                               |
| 3   | `redirectUri()`                      | Builds the callback address from config, because it must match the Partner Dashboard exactly.                                        |
| 4   | `ShopifyOAuthController::callback()` | Runs every check before touching the code Shopify sent back.                                                                         |
| 5   | `OAuthHmacVerifier::verify()`        | Recomputes the HMAC of the query string with our secret, proving Shopify built this URL.                                             |
| 6   | `session()->pull(...)`               | Reads and deletes the nonce. It must match the `state` and the shop, so a callback works once and only for the shop that started it. |
| 7   | `OAuthService::install()`            | Posts the code and our secret to Shopify server-to-server and receives the tokens.                                                   |
| 8   | `missingScopes()`                    | Confirms Shopify granted everything we asked for. A `write_` scope counts as its `read_` scope.                                      |
| 9   | `firstOrNew()` → `fill()->save()`    | Creates the shop's row, or revives the existing one with its tiers. The model's `encrypted` casts encrypt both tokens.               |
| 10  | `OAuthService::adminAppUrl()`        | Builds the address of the app inside the merchant's admin, where they are redirected.                                                |

## 6. Customers list

Purpose: show one page of the store's customers, live from Shopify, with tier
badges.

```
Customers()                                        Customers.jsx
├─ useEffect [] → api('/tiers')                           → section 7, "Load"
└─ useEffect [tier, after] → api('/customers?…')
   └─ [A]
      └─ CustomerController::index()
         ├─ CustomerIndexRequest::rules()                 tier (TierRules::TAG_PATTERN), after
         ├─ CustomerQuery::page($tier, $after)
         │  ├─ [B] query(QUERY, {query: "tag:…", first: 25, after})
         │  └─ customer($node) for each edge              flattens the node
         └─ CustomerResource::collection()->additional(['page_info' => …])
            └─ CustomerResource::toArray()

back in the browser:
setCustomers / setHasNextPage / setEndCursor
└─ CustomerList()
   └─ TierBadges({tags, tiers})
changeTier()                                              resets the cursor list
```

| #   | Function                        | What it does                                                                                                                                          |
| --- | ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `Customers()` first effect      | Loads the shop's tiers once, for the filter options and badge colours.                                                                                |
| 2   | `Customers()` second effect     | Runs on load and whenever `tier` or the cursor changes. Builds the query string and calls `api('/customers…')`.                                       |
| 3   | **A**                           | Verifies the token and resolves the shop.                                                                                                             |
| 4   | `CustomerIndexRequest::rules()` | Allows only tag-safe characters in `tier`, because it goes into Shopify's search syntax.                                                              |
| 5   | `CustomerController::index()`   | Wires the validated input to the query service and wraps the result in a Resource with `page_info`.                                                   |
| 6   | `CustomerQuery::page()`         | Turns the tier into `tag:<tier>` (or an empty search), asks for 25 customers after the cursor, via **B**. Throws if the response shape is unexpected. |
| 7   | `CustomerQuery::customer()`     | Flattens one Shopify node, pulling the email and location out of their nested objects.                                                                |
| 8   | `CustomerResource::toArray()`   | Renames keys to `first_name`, `last_name` and so on for the browser.                                                                                  |
| 9   | `CustomerList()`                | Draws the skeleton, the empty state or the table, with the pagination controls.                                                                       |
| 10  | `TierBadges()`                  | Compares the customer's tags with the tiers' tags, ignoring case. One badge per match, or "Retail".                                                   |
| 11  | `changeTier()`                  | Sets the filter and resets the cursor list, since a cursor belongs to one search.                                                                     |

Nothing is stored: customers are read from Shopify on every request.

## 7. Tier settings

Purpose: let the merchant manage tiers in our database, and copy every change
to Shopify.

```
Load
Settings()                                         Settings.jsx
├─ useEffect [] → api('/tiers')
│  └─ [A] → TierSettingController::index()
│           ├─ $shop->tierSettings()->orderBy('tag')->get()     database only
│           └─ TierSettingResource::collection()
└─ useEffect [syncs] → api('/checkout-status')            → section 8

Add
add()
└─ api('/tiers', POST) → [A]
   └─ TierSettingController::store()
      ├─ StoreTierSettingRequest::rules()                 TierRules::tag / name / discountType / discountValue / badgeTone
      ├─ $shop->tierSettings()->create()                  unique violation → 422
      ├─ syncCheckout($shop, $oauth)                      → "Sync" below
      └─ new TierSettingResource($tier)                   201

Save
save()
└─ api('/tiers', PUT {tiers}) → [A]
   └─ TierSettingController::update()
      ├─ UpdateTierSettingsRequest::rules()               TierRules per tier, ids must belong to the shop
      ├─ DB::transaction(...)                             $tier->update() for each change
      ├─ syncCheckout($shop, $oauth)
      └─ TierSettingResource::collection()

Delete
remove()
└─ api('/tiers/{id}', DELETE) → [A]
   └─ TierSettingController::destroy()
      ├─ $shop->tierSettings()->find($id)                 missing → 404
      ├─ $tier->delete()
      ├─ syncCheckout($shop, $oauth)
      └─ response()->noContent()                          204

Sync (shared by add, save, delete)
syncCheckout()
└─ TierDiscountSync::sync($shop)
   ├─ TierDiscountConfig::build($tiers)                   → {"tags": […], "tiers": […]}
   ├─ exists($discountId)                                 [B] automaticDiscountNode
   ├─ setMetafield($discountId, $json)                    [B] metafieldsSet          (discount exists)
   │   or create($json)                                   [B] discountAutomaticAppCreate
   │  └─ failOnUserErrors()
   ├─ ShopifyApiException → TierDiscountSyncException     rendered as 502 in bootstrap/app.php
   └─ $shop->save()                                       tier_discount_id, tier_discount_synced_at

after any success in the browser: setSyncs(count + 1) re-runs the checkout-status effect
```

**Load**

| #   | Function                         | What it does                                                                                                |
| --- | -------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| 1   | `Settings()` first effect        | Calls `api('/tiers')`.                                                                                      |
| 2   | `TierSettingController::index()` | Returns the shop's tiers ordered by tag. Reads only our database, so it works when Shopify is down.         |
| 3   | `TierSettingResource::toArray()` | Sends `id`, `tag`, `name`, `discount_type`, `discount_value` (as a string like `"25.00"`) and `badge_tone`. |

**Add, save, delete**

| #   | Function                                                | What it does                                                                                                                                      |
| --- | ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `add()` / `save()` / `remove()` in `Settings.jsx`       | Send the POST, PUT or DELETE, then update the page's state from the answer. On a 422 they place each message under its field.                     |
| 2   | `StoreTierSettingRequest` / `UpdateTierSettingsRequest` | Validate the input using the shared `TierRules`. The update request also checks each `id` belongs to this shop and that no two tiers share a tag. |
| 3   | `TierRules::tag()`, `discountValue()` and the rest      | One set of rules for creating and editing: tag-safe and unique per shop, percentage 0 to 100, fixed above 0, two decimals at most.                |
| 4   | `TierSettingController::store()`                        | Creates the tier. A unique-index violation from a race becomes a 422.                                                                             |
| 5   | `TierSettingController::update()`                       | Updates all submitted tiers inside one `DB::transaction`, so a failure leaves nothing half-saved.                                                 |
| 6   | `TierSettingController::destroy()`                      | Finds the tier through the shop (another shop's ID is a 404) and deletes it.                                                                      |
| 7   | `syncCheckout()`                                        | Runs after the save, outside the transaction, and starts the sync below.                                                                          |

**Sync to Shopify**

| #   | Function                                                  | What it does                                                                                                             |
| --- | --------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| 1   | `TierDiscountSync::sync()`                                | Coordinates the sync and records `tier_discount_id` and `tier_discount_synced_at` on the shop.                           |
| 2   | `TierDiscountConfig::build()`                             | Pure function: turns the tiers into the JSON the checkout Function reads. Uses the tag as the name when a tier has none. |
| 3   | `exists()`                                                | Asks Shopify (via **B**) whether the saved discount still exists, since a merchant can delete it.                        |
| 4   | `setMetafield()`                                          | Rewrites the whole JSON on the existing discount.                                                                        |
| 5   | `create()`                                                | Creates the "Wholesale tiers" automatic discount with the metafield already on it, when there is none.                   |
| 6   | `failOnUserErrors()`                                      | Shopify reports a refused mutation with HTTP 200 and a `userErrors` list; this turns that into an exception.             |
| 7   | `TierDiscountSyncException` render in `bootstrap/app.php` | Answers 502 with "saved here, but checkout still uses the previous discounts".                                           |

## 8. Checkout status

Purpose: tell the merchant whether the saved tiers are live at checkout.

```
useEffect [syncs] → api('/checkout-status')        Settings.jsx
└─ [A]
   └─ CheckoutStatusController::show()
      ├─ TierDiscountSync::status($shop)
      │  ├─ tier_discount_id null → CheckoutState::NeverSynced      no Shopify call
      │  └─ [B] query(STATUS) → Missing / Active / Inactive
      └─ new CheckoutStatusResource({state, syncedAt})

back in the browser: CheckoutStatus({status, error})
```

| #   | Function                             | What it does                                                                                                                                                            |
| --- | ------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `Settings()` second effect           | Calls `api('/checkout-status')` on load and after every add, save or delete (the `syncs` counter triggers it).                                                          |
| 2   | `CheckoutStatusController::show()`   | Asks the sync service for the state and returns it with the last sync time.                                                                                             |
| 3   | `TierDiscountSync::status()`         | No discount ID means "never synced", without calling Shopify. Otherwise asks Shopify (via **B**): gone is "missing", `ACTIVE` is "active", anything else is "inactive". |
| 4   | `CheckoutStatus()` in `Settings.jsx` | Draws the green "Active" card or the matching warning banner.                                                                                                           |

## 9. Price preview

Purpose: show what each tier would pay for one product, calculated on the
server.

```
Preview()                                          Preview.jsx
├─ useEffect [] → api('/products?limit=1')
│  └─ [A] → ProductController::index()
│           ├─ ProductIndexRequest::rules()
│           ├─ ProductQuery::first($limit)
│           │  ├─ [B] query(LIST_QUERY)
│           │  ├─ currency($data)
│           │  └─ product($node, $currency) → cents($price)
│           └─ ProductResource::collection()
├─ pickProduct()
│  └─ window.shopify.resourcePicker(...)                  Shopify's picker; no call to Laravel
└─ useEffect [productId] → api('/preview?product_id=…')
   └─ [A] → PreviewController::show()
            ├─ PreviewRequest::rules()                    product_id must be a Product gid
            ├─ ProductQuery::find($id)                    [B] query(FIND_QUERY); null → 404
            ├─ $shop->tierSettings()->orderBy('tag')->get()
            ├─ TierCalculator::calculate($priceCents, $tier)   once per tier
            └─ new PreviewResource({product, tiers})

back in the browser: PriceTable() → money(), discount()
```

| #   | Function                                               | What it does                                                                                                                                 |
| --- | ------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `Preview()` first effect                               | Calls `api('/products?limit=1')` so the page starts with a product.                                                                          |
| 2   | `ProductController::index()` → `ProductQuery::first()` | Fetches the first products by title with the shop's currency, via **B**.                                                                     |
| 3   | `ProductQuery::product()` and `cents()`                | Take the first variant's price and convert it to whole cents with exact decimal maths. A price with a fraction of a cent is refused.         |
| 4   | `pickProduct()`                                        | Opens Shopify's own product picker through App Bridge. Only the chosen product's ID and title are kept.                                      |
| 5   | `Preview()` second effect                              | Calls `api('/preview?product_id=…')` whenever the product changes.                                                                           |
| 6   | `PreviewRequest::rules()`                              | Accepts only an ID shaped like `gid://shopify/Product/123`.                                                                                  |
| 7   | `PreviewController::show()` → `ProductQuery::find()`   | Reads the price from Shopify again (never from the browser). An unknown product is a 404.                                                    |
| 8   | `TierCalculator::calculate()`                          | Pure function, called once per tier. Applies the percentage or fixed discount, rounds the final price half-up to the cent, never below zero. |
| 9   | `PriceTable()`, `money()`, `discount()`                | Draw the Retail row and one row per tier, formatting cents as currency for display only.                                                     |

## 10. Discount at checkout

Purpose: apply the tier discount in the customer's cart. This code runs on
Shopify, with no call to Laravel.

```
Customer reaches checkout
└─ Shopify runs the input query                    src/cart_lines_discounts_generate_run.graphql
   └─ cartLinesDiscountsGenerateRun(input)         src/cart_lines_discounts_generate_run.js
      ├─ input.discount.metafield.jsonValue.tiers         written by section 7's Sync
      ├─ input.cart.buyerIdentity.customer.hasTags        matched ignoring case
      ├─ for each cart line: amountOff(tier, unitPrice)   picks the best tier for that line
      └─ return { operations: [ productDiscountsAdd { candidates } ] }
         └─ Shopify applies the amounts and shows the tier's name
```

| #   | Function                                     | What it does                                                                                                                                         |
| --- | -------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `cart_lines_discounts_generate_run.graphql`  | Declares what Shopify must hand the Function: cart lines and prices, which tier tags the customer has, and the metafield.                            |
| 2   | `cartLinesDiscountsGenerateRun()`            | Reads the tiers from the metafield, keeps the ones whose tag the customer has (ignoring case), and returns nothing for guests or untagged customers. |
| 3   | `amountOff()`                                | Works out what a tier would take off one unit, only to compare tiers on the same line.                                                               |
| 4   | The returned `productDiscountsAdd` operation | Lists, per winning tier, the cart lines it applies to and its value. Shopify calculates the real amounts and shows the tier's name.                  |

## 11. Reconnect

Purpose: recover when Shopify no longer accepts the shop's tokens.

```
any [B] call
└─ ReauthorizationRequiredException                thrown in refreshAccessToken() or query()
   └─ bootstrap/app.php  $exceptions->render(...)  403 {message, reauthorize_url}
      └─ api()                                     error.reauthorizeUrl = body.reauthorize_url
         └─ page sets its error state → <Banner action={reconnectAction(error)}>
            └─ reconnectAction(error)              lib/api.js
               └─ window.open(error.reauthorizeUrl, '_top')
                  └─ section 5 (OAuth install), which repairs the existing shops row
```

| #   | Function                               | What it does                                                                                                            |
| --- | -------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| 1   | `ReauthorizationRequiredException`     | Thrown inside **B** when the refresh token is unusable or Shopify answers 401. Carries the URL from `reauthorizeUrl()`. |
| 2   | `OAuthService::reauthorizeUrl()`       | Builds the absolute `/auth?shop=…` address from config.                                                                 |
| 3   | Render callback in `bootstrap/app.php` | Answers 403 with a plain message and `reauthorize_url`.                                                                 |
| 4   | `api()`                                | Copies `reauthorize_url` onto the thrown error.                                                                         |
| 5   | `reconnectAction()` in `lib/api.js`    | Returns the banner's "Reconnect" button for an error that has the URL, and nothing for any other error.                 |
| 6   | `window.open(url, '_top')`             | Leaves the iframe and loads `/auth` in the whole tab, which runs section 5 and repairs the shop's row.                  |

To see it on a dev store: blank both `access_token` and `refresh_token` in the
`shops` row, or blank `refresh_token` and set `access_token_expires_at` to a
past time. Blanking the refresh token alone changes nothing until the access
token is within a minute of expiring.

---

# Part two: the frontend from the inside

Each page section has three tables: the state it holds, the functions that
change that state, and what it draws in each situation.

## 12. Startup: `app.blade.php`, `main.jsx`, `App.jsx`

Purpose: get React running inside the admin's iframe and pick the page for
the current path.

| #   | Code                                                       | What it does                                                                                                      |
| --- | ---------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| 1   | `<meta name="shopify-api-key">` in `app.blade.php`         | Gives App Bridge our public client ID. Never the secret.                                                          |
| 2   | `<script src="…/app-bridge.js">`                           | Loaded first and synchronously, so `fetch` is wrapped before our code runs. Also provides `window.shopify`.       |
| 3   | `main.jsx`                                                 | Imports the Polaris stylesheet and renders `<App />` into `<div id="root">`, inside `StrictMode`.                 |
| 4   | `const embedded = window.self !== window.top` in `App.jsx` | True when the page is inside a frame. Opened directly in a tab, it is false.                                      |
| 5   | `App()` when not embedded                                  | Draws only a warning `Banner`: "Open this app from your Shopify admin". No API calls are made.                    |
| 6   | `App()` when embedded                                      | Wraps everything in Polaris's `AppProvider` (translations) and `Frame` (needed for toasts), then `BrowserRouter`. |
| 7   | `<Routes>`                                                 | `/` → `Customers`, `/settings` → `Settings`, `/preview` → `Preview`, anything else redirects to `/`.              |

Moving between pages is done with `useNavigate()` from page buttons, so it
never reloads the iframe.

## 13. The API wrapper: `lib/api.js`

Purpose: one place for every request, and one consistent error shape for the
pages.

| #   | Code                                | What it does                                                                                                                                   |
| --- | ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `api(path, options)`                | Calls `fetch('/api' + path)` with JSON headers. The caller passes `method` and `body` for writes.                                              |
| 2   | `response.json().catch(() => null)` | Tolerates responses with no JSON body, such as a 204 or an HTML error page from the tunnel.                                                    |
| 3   | `if (!response.ok)`                 | Builds an `Error` whose message is Laravel's `message`, or the status code as a fallback.                                                      |
| 4   | `error.status`                      | Lets a page tell a 422 from any other failure.                                                                                                 |
| 5   | `error.errors`                      | Laravel's per-field messages on a 422, keyed by field path.                                                                                    |
| 6   | `error.reauthorizeUrl`              | Set only on the 403 "reconnect" response.                                                                                                      |
| 7   | `reconnectAction(error)`            | Returns a `{ content: 'Reconnect', onAction }` object for a Polaris `Banner` when the error has that URL; otherwise `undefined`, so no button. |

## 14. Customers page: `Customers.jsx`

Purpose: list customers with a tier filter, paging and badges. Read-only.

**State**

| Variable                   | Holds                                                          | Changed by                     |
| -------------------------- | -------------------------------------------------------------- | ------------------------------ |
| `tier`                     | The selected tier's tag; `''` means all customers              | `changeTier()`                 |
| `cursors`                  | The cursor each visited page started after; starts as `[null]` | Next, Previous, `changeTier()` |
| `customers`                | The rows of the current page                                   | The customers effect           |
| `hasNextPage`, `endCursor` | Paging info from the last response                             | The customers effect           |
| `loading`                  | True while a customers request is in flight                    | The customers effect           |
| `error`                    | The whole `Error` object, so it can carry the reconnect URL    | The customers effect           |
| `tiers`                    | The shop's tiers, for the filter and the badges                | The tiers effect               |
| `tiersError`               | A message if the tiers failed to load                          | The tiers effect               |

`after` is not state: it is derived on every render as the last item of
`cursors`.

**Effects and handlers**

| #   | Function                               | What it does                                                                                                                                   |
| --- | -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Tiers effect, deps `[]`                | Runs once. Calls `api('/tiers')` and stores the result.                                                                                        |
| 2   | Customers effect, deps `[tier, after]` | Runs on load and whenever the filter or the page changes. Sets `loading`, clears `error`, builds the query string, calls `api('/customers…')`. |
| 3   | `let ignore` + the cleanup function    | When the effect re-runs, the previous run's `ignore` becomes true, so a slow old answer cannot overwrite a newer one.                          |
| 4   | `changeTier(value)`                    | Sets `tier` and resets `cursors` to `[null]`, which triggers the customers effect for page 1.                                                  |
| 5   | `pagination.onNext`                    | Appends `endCursor` to `cursors`. `after` changes, so the effect fetches the next page.                                                        |
| 6   | `pagination.onPrevious`                | Removes the last cursor. The effect refetches the earlier page using a cursor it already has.                                                  |
| 7   | `fullName()`                           | Joins first and last name, or shows "No name".                                                                                                 |

**What it draws**

| Situation                | Result                                                                                                                              |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------- |
| Always                   | `Page` titled "Customers" with buttons to Tier settings and Price preview; a `Select` listing "All customers" plus each tier's name |
| `error` set              | Critical `Banner` "Couldn't load customers", with Reconnect if available; the table area is empty                                   |
| `tiersError` set         | Warning `Banner`; customers are still listed, without badges                                                                        |
| `loading`                | `SkeletonBodyText` in place of the table                                                                                            |
| No customers             | `EmptyState` "No customers in this tier"                                                                                            |
| Otherwise                | `IndexTable` with Name, Email, Tier, Location and "Page N" pagination                                                               |
| Tier cell (`TierBadges`) | One `Badge` per matching tier in its saved colour, or the text "Retail"                                                             |

## 15. Settings page: `Settings.jsx`

Purpose: edit tiers, and show whether they are live at checkout. The only
page that writes.

**State**

| Variable                            | Holds                                                                  | Changed by                                             |
| ----------------------------------- | ---------------------------------------------------------------------- | ------------------------------------------------------ |
| `tiers`                             | The tier cards, including unsaved edits                                | Load effect, `change()`, `save()`, `add()`, `remove()` |
| `loading`, `loadError`              | Status of the initial load                                             | Load effect                                            |
| `saving`, `saveError`               | Status of Save; `saveError` is a message for the banner                | `save()`                                               |
| `fieldErrors`                       | The 422 errors from Save, keyed like `tiers.0.tag`                     | `save()`, cleared by `remove()`                        |
| `toast`                             | The toast's text, or null                                              | `save()`, `add()`, `remove()`                          |
| `draft`                             | The Add dialog's form; null means the dialog is closed                 | `openAdd()`, typing, `add()`                           |
| `adding`, `addError`, `draftErrors` | Status and errors of the Add dialog                                    | `add()`                                                |
| `deleting`                          | The tier awaiting delete confirmation; null means the dialog is closed | `askDelete()`, `remove()`                              |
| `removing`, `deleteError`           | Status of the delete                                                   | `remove()`                                             |
| `checkout`, `checkoutError`         | `{ state, synced_at }` from Shopify, or the error                      | Checkout effect                                        |
| `syncs`                             | A counter of successful writes                                         | `save()`, `add()`, `remove()`                          |

**Effects and handlers**

| #   | Function                        | What it does                                                                                                                                                                   |
| --- | ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | Load effect, deps `[]`          | Calls `api('/tiers')` once.                                                                                                                                                    |
| 2   | Checkout effect, deps `[syncs]` | Calls `api('/checkout-status')` on load and again each time `syncs` increases.                                                                                                 |
| 3   | `change(index, field, value)`   | Replaces one field of one card with a copy, leaving the others untouched. Nothing is sent yet.                                                                                 |
| 4   | `save()`                        | Sends all cards with `PUT`. On success, replaces `tiers` with the server's version, bumps `syncs`, shows "Tiers saved".                                                        |
| 5   | `save()` on a 422               | Stores `e.errors` in `fieldErrors`. Keys that match no visible field (tested with the `FIELD_ERROR` pattern) are joined into `saveError` for the banner.                       |
| 6   | `save()` on any other error     | Puts the message in `saveError`. This includes the 502 "saved here, not at checkout".                                                                                          |
| 7   | `openAdd()`                     | Sets `draft` to `NEW_TIER` (empty tag, percentage, blue), which opens the dialog.                                                                                              |
| 8   | `add()`                         | Sends the draft with `POST`. On success, appends the new tier, closes the dialog, bumps `syncs`, shows "Tier added". A 422 goes to `draftErrors`, anything else to `addError`. |
| 9   | `askDelete(tier)`               | Sets `deleting`, which opens the confirmation dialog.                                                                                                                          |
| 10  | `remove()`                      | Sends `DELETE`. On success, filters the tier out, clears `fieldErrors` (their positions have shifted), closes the dialog, bumps `syncs`, shows "Tier deleted".                 |
| 11  | `fieldError(key)`               | Returns the first message for a key such as `tiers.1.discount_value`.                                                                                                          |
| 12  | `dateTime(iso)`                 | Formats the sync time in the merchant's own language and time zone.                                                                                                            |

**What it draws**

| Situation                          | Result                                                                                                                                         |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Always                             | `Page` titled "Settings" with a back button, "Add tier" as the primary action, and a link to Price preview                                     |
| `loadError` / `saveError`          | Critical `Banner`; the save one can be dismissed                                                                                               |
| Checkout status (`CheckoutStatus`) | "Active" card with the sync time, or a warning for `inactive` / `missing`, or an info banner for `never_synced`; nothing until Shopify answers |
| `loading`                          | A `Card` with `SkeletonBodyText`                                                                                                               |
| No tiers                           | A `Card` saying the store has no tiers yet                                                                                                     |
| Each tier                          | A `Card` keyed by `tier.id`, with a live `Badge` preview, a Delete button, and `TierFields`                                                    |
| At least one tier                  | A note about the "Wholesale tiers" discount and the primary Save `Button`                                                                      |
| `draft !== null`                   | The "Add tier" `Modal`, containing the same `TierFields`                                                                                       |
| `deleting !== null`                | The delete confirmation `Modal` with a destructive button                                                                                      |
| `toast` set                        | A `Toast`                                                                                                                                      |

`TierFields` is the form shared by the cards and the Add dialog. It receives
`tier`, `onChange(field, value)` and `error(field)` as props, and draws Tag,
Name, Discount type, Discount and Badge colour. Each input's `error` prop
shows the server's message under it. The Discount field shows a `%` suffix for
percentages and a currency hint for fixed amounts.

## 16. Price preview page: `Preview.jsx`

Purpose: pick a product and show each tier's price for it. Read-only.

**State**

| Variable                         | Holds                                                | Changed by                    |
| -------------------------------- | ---------------------------------------------------- | ----------------------------- |
| `product`                        | `{ id, title }` of the product being priced, or null | Start effect, `pickProduct()` |
| `starting`, `startError`         | Status of loading the first product                  | Start effect                  |
| `pickerError`                    | A message if the picker failed to open               | `pickProduct()`               |
| `preview`                        | `{ product, tiers }` from the server, or null        | Preview effect                |
| `previewLoading`, `previewError` | Status of the price request                          | Preview effect                |

`productId` is derived as `product?.id` on every render.

**Effects and handlers**

| #   | Function                           | What it does                                                                                                                                                |
| --- | ---------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Start effect, deps `[]`            | Calls `api('/products?limit=1')` and uses the first product, so a table appears straight away.                                                              |
| 2   | Preview effect, deps `[productId]` | Does nothing without a product. Otherwise clears the old `preview` (so old prices cannot show under a new name), then calls `api('/preview?product_id=…')`. |
| 3   | `pickProduct()`                    | Awaits `window.shopify.resourcePicker(...)`, Shopify's own picker with variants hidden. A selection sets `product`; cancelling changes nothing.             |
| 4   | `money(amount, currency)`          | Formats a number as currency with `Intl.NumberFormat`. Display only.                                                                                        |
| 5   | `discount(tier, currency)`         | Produces "25% off" or "$5.00 off".                                                                                                                          |

**What it draws**

| Situation                      | Result                                                                                                       |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------ |
| Always                         | `Page` titled "Price preview" with a back button and a link to Tier settings                                 |
| `startError` / `previewError`  | Critical `Banner`, with Reconnect if available                                                               |
| `pickerError`                  | Dismissible critical `Banner`                                                                                |
| Store has no products          | `ProductHeader` shows "This store has no products yet."                                                      |
| Otherwise                      | `ProductHeader` shows the product's title and a "Choose product" `Button`                                    |
| `starting` or `previewLoading` | `PriceTable` shows `SkeletonBodyText`                                                                        |
| `preview` loaded               | A `DataTable` with a "Retail" row at the base price, then one row per tier with its discount and final price |

## 17. Patterns that repeat on every page

- **Fetch in an effect, guarded by `ignore`.** Every load uses the same
  shape: set loading, call `api()`, store the result or the error, clear
  loading.
- **Errors that can reconnect are stored whole.** `error`, `startError`,
  `previewError` and `checkoutError` keep the `Error` object so
  `reconnectAction()` can read its URL. Errors that cannot (tiers, picker,
  save) keep only the message.
- **State is replaced, never edited in place.** `map`, `filter` and spread
  create new arrays and objects, which is what tells React to redraw.
- **The server is the source of truth.** After a save, the page shows what
  Laravel returned, not what was typed, and all prices are calculated on the
  server.
