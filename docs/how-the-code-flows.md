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
- Part two (sections 12 to 19) covers each frontend file from the inside.
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
| Frontend    | [lib/api.ts](../resources/js/lib/api.ts), [lib/types.ts](../resources/js/lib/types.ts), [hooks/useApi.ts](../resources/js/hooks/useApi.ts), [pages/](../resources/js/pages), [components/](../resources/js/components) |

---

# Part one: the flows through the server

## 2. Chain A: every `/api` request (the way in)

Purpose: prove which shop the browser request belongs to before any
controller runs.

```
Page component (Customers.tsx / Settings.tsx / Preview.tsx), usually through useApi()
└─ api(path, options)                              lib/api.ts
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
| 1   | `api()` in `lib/api.ts` | The one place the frontend calls `fetch`. Adds JSON headers, and throws an `ApiError` carrying `status`, `errors` and `reauthorizeUrl` when the response is a failure. |
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
      └─ @vite('resources/js/main.tsx')
         └─ createRoot(...).render(<App />)               main.tsx
            └─ App()                                      App.tsx   <Routes>: / , /settings , /preview
```

| #   | Function                                             | What it does                                                                                                                        |
| --- | ---------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `Route::view('/{path?}', 'app')` in `routes/web.php` | Serves the same page for `/`, `/settings` and `/preview`, so a reload on any of them works. Excludes `/api`, `/docs`, `/telescope`. |
| 2   | `app.blade.php`                                      | Outputs the API key in a meta tag, loads App Bridge first, then the Vite bundle.                                                    |
| 3   | `main.tsx`                                           | Imports the Polaris CSS and mounts `<App />` into `<div id="root">`.                                                                |
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
Customers()                                        pages/Customers.tsx
├─ useApi('/tiers')                                       → section 7, "Load"
└─ useApi('/customers?…')                                 runs again when tier or the cursor changes the path
   └─ api(path)                                           hooks/useApi.ts
   └─ [A]
      └─ CustomerController::index()
         ├─ CustomerIndexRequest::rules()                 tier (TierRules::TAG_PATTERN), after
         ├─ CustomerQuery::page($tier, $after)
         │  ├─ [B] query(QUERY, {query: "tag:…", first: 25, after})
         │  └─ customer($node) for each edge              flattens the node
         └─ CustomerResource::collection()->additional(['page_info' => …])
            └─ CustomerResource::toArray()

back in the browser:
useApi() returns { data, error, loading } and the page redraws
└─ CustomerList()                                        components/CustomerList.tsx
   └─ TierBadges({tags, tiers})                           components/TierBadges.tsx
changeTier()                                              resets the cursor list
```

| #   | Function                        | What it does                                                                                                                                          |
| --- | ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `useApi('/tiers')` in `Customers()` | Loads the shop's tiers once, for the filter options and badge colours. |
| 2   | `useApi('/customers…')` in `Customers()` | The page builds the path from `tier` and the cursor; the hook loads it on first draw and again whenever the path changes. |
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
Settings()                                         pages/Settings.tsx
├─ useEffect [] → api('/tiers')
│  └─ [A] → TierSettingController::index()
│           ├─ $shop->tierSettings()->orderBy('tag')->get()     database only
│           └─ TierSettingResource::collection()
└─ useApi('/checkout-status')                             → section 8

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

after any success in the browser: checkout.reload() asks for the checkout status again
```

**Load**

| #   | Function                         | What it does                                                                                                |
| --- | -------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| 1   | The load effect in `Settings()` | Calls `api('/tiers')`. Written by hand, not with `useApi()`, because these tiers are then edited in place. |
| 2   | `TierSettingController::index()` | Returns the shop's tiers ordered by tag. Reads only our database, so it works when Shopify is down.         |
| 3   | `TierSettingResource::toArray()` | Sends `id`, `tag`, `name`, `discount_type`, `discount_value` (as a string like `"25.00"`) and `badge_tone`. |

**Add, save, delete**

| #   | Function                                                | What it does                                                                                                                                      |
| --- | ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `add()` / `save()` / `remove()` in `Settings.tsx`       | Send the POST, PUT or DELETE, then update the page's state from the answer. On a 422 they place each message under its field.                     |
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
useApi('/checkout-status')                         pages/Settings.tsx; reload() after each write
└─ [A]
   └─ CheckoutStatusController::show()
      ├─ TierDiscountSync::status($shop)
      │  ├─ tier_discount_id null → CheckoutState::NeverSynced      no Shopify call
      │  └─ [B] query(STATUS) → Missing / Active / Inactive
      └─ new CheckoutStatusResource({state, syncedAt})

back in the browser: CheckoutStatus({status, error})     components/CheckoutStatus.tsx
```

| #   | Function                             | What it does                                                                                                                                                            |
| --- | ------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `useApi('/checkout-status')` in `Settings()` | Loads the status when the page opens. `save()`, `add()` and `remove()` call its `reload()` after every successful write. |
| 2   | `CheckoutStatusController::show()`   | Asks the sync service for the state and returns it with the last sync time.                                                                                             |
| 3   | `TierDiscountSync::status()`         | No discount ID means "never synced", without calling Shopify. Otherwise asks Shopify (via **B**): gone is "missing", `ACTIVE` is "active", anything else is "inactive". |
| 4   | `CheckoutStatus()` in `components/` | Draws the green "Active" card or the matching warning banner. |

## 9. Price preview

Purpose: show what each tier would pay for one product, calculated on the
server.

```
Preview()                                          pages/Preview.tsx
├─ useApi('/products?limit=1')
│  └─ [A] → ProductController::index()
│           ├─ ProductIndexRequest::rules()
│           ├─ ProductQuery::first($limit)
│           │  ├─ [B] query(LIST_QUERY)
│           │  ├─ currency($data)
│           │  └─ product($node, $currency) → cents($price)
│           └─ ProductResource::collection()
├─ pickProduct()
│  └─ shopify.resourcePicker(...)                         Shopify's picker; no call to Laravel
└─ useApi('/preview?product_id=…')                        null, so nothing is loaded, until a product is known
   └─ [A] → PreviewController::show()
            ├─ PreviewRequest::rules()                    product_id must be a Product gid
            ├─ ProductQuery::find($id)                    [B] query(FIND_QUERY); null → 404
            ├─ $shop->tierSettings()->orderBy('tag')->get()
            ├─ TierCalculator::calculate($priceCents, $tier)   once per tier
            └─ new PreviewResource({product, tiers})

back in the browser: PriceTable() → money(), discount()   components/PriceTable.tsx, lib/format.ts
```

| #   | Function                                               | What it does                                                                                                                                 |
| --- | ------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `useApi('/products?limit=1')` in `Preview()` | Loads the store's first product, so the page starts with one. |
| 2   | `ProductController::index()` → `ProductQuery::first()` | Fetches the first products by title with the shop's currency, via **B**.                                                                     |
| 3   | `ProductQuery::product()` and `cents()`                | Take the first variant's price and convert it to whole cents with exact decimal maths. A price with a fraction of a cent is refused.         |
| 4   | `pickProduct()`                                        | Opens Shopify's own product picker through App Bridge. Only the chosen product's ID and title are kept.                                      |
| 5   | `useApi('/preview?product_id=…')` in `Preview()` | Loads the prices whenever the product, and so the path, changes. |
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
      └─ api()                                     throws ApiError with reauthorizeUrl = body.reauthorize_url
         └─ useApi() keeps the error → <Banner action={reconnectAction(error)}>
            └─ reconnectAction(error)              lib/api.ts
               └─ window.open(error.reauthorizeUrl, '_top')
                  └─ section 5 (OAuth install), which repairs the existing shops row
```

| #   | Function                               | What it does                                                                                                            |
| --- | -------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| 1   | `ReauthorizationRequiredException`     | Thrown inside **B** when the refresh token is unusable or Shopify answers 401. Carries the URL from `reauthorizeUrl()`. |
| 2   | `OAuthService::reauthorizeUrl()`       | Builds the absolute `/auth?shop=…` address from config.                                                                 |
| 3   | Render callback in `bootstrap/app.php` | Answers 403 with a plain message and `reauthorize_url`.                                                                 |
| 4   | `api()` | Puts `reauthorize_url` on the `ApiError` it throws. |
| 5   | `reconnectAction()` in `lib/api.ts` | Returns the banner's "Reconnect" button for an `ApiError` that has the URL, and nothing for any other error. |
| 6   | `window.open(url, '_top')`             | Leaves the iframe and loads `/auth` in the whole tab, which runs section 5 and repairs the shop's row.                  |

To see it on a dev store: blank both `access_token` and `refresh_token` in the
`shops` row, or blank `refresh_token` and set `access_token_expires_at` to a
past time. Blanking the refresh token alone changes nothing until the access
token is within a minute of expiring.

---


# Part two: the frontend from the inside

The frontend is TypeScript. Each page section has three tables: the state it
holds, the functions that change that state, and what it draws in each
situation.

```
resources/js/
  main.tsx, App.tsx      startup and routes
  lib/api.ts             the one fetch wrapper, ApiError, reconnectAction()
  lib/types.ts           the shapes the API answers with
  lib/format.ts          tierLabel(), money(), dateTime()
  hooks/useApi.ts        "load this path and keep the answer"
  pages/                 Customers.tsx, Settings.tsx, Preview.tsx
  components/            CustomerList, TierBadges, CheckoutStatus, TierFields,
                         ProductHeader, PriceTable
```

## 12. Startup: `app.blade.php`, `main.tsx`, `App.tsx`

Purpose: get React running inside the admin's iframe and pick the page for
the current path.

| #   | Code                                                        | What it does                                                                                                      |
| --- | ----------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| 1   | `<meta name="shopify-api-key">` in `app.blade.php`          | Gives App Bridge our public client ID. Never the secret.                                                          |
| 2   | `<script src="…/app-bridge.js">`                            | Loaded first and synchronously, so `fetch` is wrapped before our code runs. Also provides the `shopify` global.   |
| 3   | `main.tsx`                                                  | Imports the Polaris stylesheet and renders `<App />` into `<div id="root">`, inside `StrictMode`.                 |
| 4   | `const embedded = window.self !== window.top` in `App.tsx`  | True when the page is inside a frame. Opened directly in a tab, it is false.                                      |
| 5   | `App()` when not embedded                                   | Draws only a warning `Banner`: "Open this app from your Shopify admin". No API calls are made.                    |
| 6   | `App()` when embedded                                       | Wraps everything in Polaris's `AppProvider` (translations) and `Frame` (needed for toasts), then `BrowserRouter`. |
| 7   | `<Routes>`                                                  | `/` → `Customers`, `/settings` → `Settings`, `/preview` → `Preview`, anything else redirects to `/`.              |

Moving between pages is done with `useNavigate()` from page buttons, so it
never reloads the iframe.

## 13. The API wrapper: `lib/api.ts`

Purpose: one place for every request, and one error type for the pages.

| #   | Code                                  | What it does                                                                                                                                       |
| --- | ------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `api<T>(path, options)`               | Calls `fetch('/api' + path)` with JSON headers. The caller passes `method` and `body` for writes, and names the body it expects as `T`.            |
| 2   | `response.json().catch(() => null)`   | Tolerates responses with no JSON body, such as a 204 or an HTML error page from the tunnel.                                                        |
| 3   | `class ApiError extends Error`        | What `api()` throws on a failed response. Its message is Laravel's `message`, or the status code as a fallback.                                    |
| 4   | `ApiError.status`                     | Lets a page tell a 422 from any other failure.                                                                                                     |
| 5   | `ApiError.errors`                     | Laravel's per-field messages on a 422, keyed by field path.                                                                                        |
| 6   | `ApiError.reauthorizeUrl`             | Set only on the 403 "reconnect" response.                                                                                                          |
| 7   | `errorMessage(error)`                 | The text of whatever a `catch` block caught. A request that never got an answer throws fetch's own `TypeError`, not an `ApiError`.                 |
| 8   | `reconnectAction(error)`              | Returns a `{ content: 'Reconnect', onAction }` object for a Polaris `Banner` when the error is an `ApiError` with that URL; otherwise `undefined`. |

`T` is not checked against what the server really sent. The types are kept in
step with the API Resources by hand.

## 14. The API's shapes: `lib/types.ts`

Purpose: one TypeScript type per Laravel API Resource, so a page that reads a
field the API does not send fails the type check.

| Type                       | Mirrors                                    | Used by                                         |
| -------------------------- | ------------------------------------------ | ----------------------------------------------- |
| `Tier`                     | `TierSettingResource`                      | All three pages                                 |
| `TierValues`, `TierField`  | A tier without its `id`, and its field names | The Settings cards and the Add dialog         |
| `DiscountType`, `BadgeTone` | `App\Enums\DiscountType`, `App\Enums\BadgeTone` | `Tier`                                    |
| `Customer`                 | `CustomerResource`                         | Customers page                                  |
| `CustomersPage`            | The `GET /api/customers` body, with `page_info` | Customers page                             |
| `Product`                  | `ProductResource`                          | Price preview                                   |
| `Preview`                  | `PreviewResource`                          | Price preview                                   |
| `CheckoutStatus`           | `CheckoutStatusResource`                   | Settings page                                   |
| `Data<T>`                  | Laravel's `{ data: … }` wrapper            | Every `api()` and `useApi()` call               |

## 15. The loading hook: `hooks/useApi.ts`

Purpose: the one pattern every read shared, written once. "Load this path,
and give me the answer, the error, and whether it is still loading."

| #   | Code                                    | What it does                                                                                                                                             |
| --- | --------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `useApi<T>(path)`                       | Returns `{ data, error, loading, reload }`. `data` is the response body, typed as `T`. `error` is the whole `Error`, so it can carry the reconnect URL. |
| 2   | The effect, deps `[path, reloads]`      | Calls `api(path)` on first draw, when the path changes, and when `reload()` is called.                                                                   |
| 3   | `let ignore` + the cleanup function     | When the effect runs again, the previous run's `ignore` becomes true, so a slow old answer cannot overwrite a newer one.                                 |
| 4   | A changed path                          | Is a new question: `data` and `error` are cleared and `loading` is true, so old prices can never show under a new product's name.                        |
| 5   | `reload()`                              | Asks the same question again. The old answer stays on screen until the new one arrives.                                                                  |
| 6   | `path === null`                         | Means "nothing to load yet". The hook does nothing and `loading` is false.                                                                               |

It is built only from `useState`, `useEffect` and `useRef`. The Settings
page's tier list does not use it, because those tiers are edited in place and
so are the page's own state.

## 16. Customers page: `pages/Customers.tsx`

Purpose: list customers with a tier filter, paging and badges. Read-only.

**State**

| Name         | Holds                                                           | Changed by                     |
| ------------ | --------------------------------------------------------------- | ------------------------------ |
| `tier`       | The selected tier's tag; `''` means all customers               | `changeTier()`                 |
| `cursors`    | The cursor each visited page started after; starts as `[null]`  | Next, Previous, `changeTier()` |
| `tiersLoad`  | `useApi('/tiers')`: the shop's tiers, for the filter and badges | The hook, once                 |
| `page`       | `useApi('/customers…')`: the rows, `page_info`, error, loading  | The hook, on every path change |

Everything else is worked out on each draw: `after` (the last cursor), the
query string, `tiers`, `customers` and `pageInfo`.

**Handlers**

| #   | Function                 | What it does                                                                                              |
| --- | ------------------------ | --------------------------------------------------------------------------------------------------------- |
| 1   | `changeTier(value)`      | Sets `tier` and resets `cursors` to `[null]`. The path changes, so the hook loads page 1 of that tier.    |
| 2   | `pagination.onNext`      | Appends `page_info.end_cursor` to `cursors`. The path changes, so the hook loads the next page.           |
| 3   | `pagination.onPrevious`  | Removes the last cursor. The hook reloads the earlier page with a cursor the page already has.            |

**What it draws**

| Situation                              | Result                                                                                                                              |
| -------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| Always                                 | `Page` titled "Customers" with buttons to Tier settings and Price preview; a `Select` listing "All customers" plus each tier's name |
| `page.error` set                       | Critical `Banner` "Couldn't load customers", with Reconnect if available; the table area is empty                                   |
| `tiersLoad.error` set                  | Warning `Banner`; customers are still listed, without badges                                                                        |
| `page.loading`                         | `CustomerList` shows `SkeletonBodyText` in place of the table                                                                       |
| No customers                           | `CustomerList` shows `EmptyState` "No customers in this tier"                                                                       |
| Otherwise                              | `CustomerList` shows an `IndexTable` with Name, Email, Tier, Location and "Page N" pagination                                       |
| Tier cell (`components/TierBadges.tsx`) | One `Badge` per matching tier in its saved colour, or the text "Retail"                                                            |

## 17. Settings page: `pages/Settings.tsx`

Purpose: edit tiers, and show whether they are live at checkout. The only
page that writes.

**State**

| Variable                             | Holds                                                                  | Changed by                                              |
| ------------------------------------ | ---------------------------------------------------------------------- | ------------------------------------------------------- |
| `tiers`                              | The tier cards, including unsaved edits                                | Load effect, `change()`, `save()`, `add()`, `remove()`  |
| `loading`, `loadError`               | Status of the initial load                                             | Load effect                                             |
| `saving`, `saveError`                | Status of Save; `saveError` is a message for the banner                | `save()`                                                |
| `fieldErrors`                        | The 422 errors from Save, keyed like `tiers.0.tag`                     | `save()`, cleared by `remove()`                         |
| `toast`                              | The toast's text, or null                                              | `save()`, `add()`, `remove()`                           |
| `draft`                              | The Add dialog's form; null means the dialog is closed                 | `openAdd()`, typing, `add()`                            |
| `adding`, `addError`, `draftErrors`  | Status and errors of the Add dialog                                    | `add()`                                                 |
| `deleting`                           | The tier awaiting delete confirmation; null means the dialog is closed | `askDelete()`, `remove()`                               |
| `removing`, `deleteError`            | Status of the delete                                                   | `remove()`                                              |
| `checkout`                           | `useApi('/checkout-status')`: `{ state, synced_at }`, or the error     | The hook; `checkout.reload()` after each write          |

**Effects and handlers**

| #   | Function                       | What it does                                                                                                                                                                             |
| --- | ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Load effect, deps `[]`         | Calls `api('/tiers')` once and puts the answer in `tiers`.                                                                                                                               |
| 2   | `change(index, field, value)`  | Replaces one field of one card with a copy, leaving the others untouched. Nothing is sent yet.                                                                                           |
| 3   | `save()`                       | Sends all cards with `PUT`. On success, replaces `tiers` with the server's version, calls `checkout.reload()`, shows "Tiers saved".                                                      |
| 4   | `save()` on a 422              | Stores the `ApiError`'s `errors` in `fieldErrors`. Keys that match no visible field (tested with the `FIELD_ERROR` pattern) are joined into `saveError` for the banner.                  |
| 5   | `save()` on any other error    | Puts the message in `saveError`. This includes the 502 "saved here, not at checkout".                                                                                                    |
| 6   | `openAdd()`                    | Sets `draft` to `NEW_TIER` (empty tag, percentage, blue), which opens the dialog.                                                                                                        |
| 7   | `add()`                        | Sends the draft with `POST`. On success, appends the new tier, closes the dialog, calls `checkout.reload()`, shows "Tier added". A 422 goes to `draftErrors`, anything else to `addError`. |
| 8   | `askDelete(tier)`              | Sets `deleting`, which opens the confirmation dialog.                                                                                                                                    |
| 9   | `remove()`                     | Sends `DELETE`. On success, filters the tier out, clears `fieldErrors` (their positions have shifted), closes the dialog, calls `checkout.reload()`, shows "Tier deleted".               |
| 10  | `fieldError(key)`              | Returns the first message for a key such as `tiers.1.discount_value`.                                                                                                                    |

**What it draws**

| Situation                                        | Result                                                                                                                                          |
| ------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| Always                                           | `Page` titled "Settings" with a back button, "Add tier" as the primary action, and a link to Price preview                                      |
| `loadError` / `saveError`                        | Critical `Banner`; the save one can be dismissed                                                                                                |
| Checkout status (`components/CheckoutStatus.tsx`) | "Active" card with the sync time, or a warning for `inactive` / `missing`, or an info banner for `never_synced`; nothing until Shopify answers |
| `loading`                                        | A `Card` with `SkeletonBodyText`                                                                                                                |
| No tiers                                         | A `Card` saying the store has no tiers yet                                                                                                      |
| Each tier                                        | A `Card` keyed by `tier.id`, with a live `Badge` preview, a Delete button, and `TierFields`                                                     |
| At least one tier                                | A note about the "Wholesale tiers" discount and the primary Save `Button`                                                                       |
| `draft !== null`                                 | The "Add tier" `Modal`, containing the same `TierFields`                                                                                        |
| `deleting !== null`                              | The delete confirmation `Modal` with a destructive button                                                                                       |
| `toast` set                                      | A `Toast`                                                                                                                                       |

`components/TierFields.tsx` is the form shared by the cards and the Add
dialog. It receives `tier`, `onChange(field, value)` and `error(field)` as
props, and draws Tag, Name, Discount type, Discount and Badge colour. Each
input's `error` prop shows the server's message under it. The Discount field
shows a `%` suffix for percentages and a currency hint for fixed amounts. The
two option lists (`TYPE_OPTIONS`, `TONE_OPTIONS`) live in the same file.

## 18. Price preview page: `pages/Preview.tsx`

Purpose: pick a product and show each tier's price for it. Read-only.

**State**

| Name          | Holds                                                                   | Changed by                     |
| ------------- | ----------------------------------------------------------------------- | ------------------------------ |
| `picked`      | `{ id, title }` of the product chosen in the picker, or null            | `pickProduct()`                |
| `pickerError` | A message if the picker failed to open                                  | `pickProduct()`                |
| `start`       | `useApi('/products?limit=1')`: the store's first product                | The hook, once                 |
| `prices`      | `useApi('/preview?product_id=…')`: `{ product, tiers }`, error, loading | The hook, when the product changes |

`product` is worked out on each draw: the picked product, or else the first
one from `start`.

**Handlers**

| #   | Function         | What it does                                                                                                                            |
| --- | ---------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `pickProduct()`  | Awaits `shopify.resourcePicker(...)`, Shopify's own picker with variants hidden. A selection sets `picked`; cancelling changes nothing. |

**What it draws**

| Situation                              | Result                                                                                                       |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Always                                 | `Page` titled "Price preview" with a back button and a link to Tier settings                                 |
| `start.error` / `prices.error`         | Critical `Banner`, with Reconnect if available                                                               |
| `pickerError`                          | Dismissible critical `Banner`                                                                                |
| Store has no products                  | `ProductHeader` shows "This store has no products yet."                                                      |
| Otherwise                              | `ProductHeader` shows the product's title and a "Choose product" `Button`                                    |
| `start.loading` or `prices.loading`    | `PriceTable` shows `SkeletonBodyText`                                                                        |
| Prices loaded                          | A `DataTable` with a "Retail" row at the base price, then one row per tier with its discount and final price |

`money()` and `tierLabel()` come from `lib/format.ts`; `discount()`, which
produces "25% off" or "$5.00 off", lives beside `PriceTable`.

## 19. Patterns that repeat on every page

- **Reads go through `useApi()`.** The page names a path and the body it
  expects; the hook does the loading, the error handling and the guard
  against out-of-order answers.
- **Errors are kept whole where they can reconnect.** `useApi()` returns the
  `Error` itself, and `reconnectAction()` reads the URL from it. Errors that
  cannot reconnect (the Settings load, the picker, a save) keep only the
  message.
- **State is replaced, never edited in place.** `map`, `filter` and spread
  create new arrays and objects, which is what tells React to redraw.
- **Values that can be worked out are not stored.** `after`, `tiers`,
  `customers` and `product` are computed on each draw from the state and the
  hook's answer.
- **The server is the source of truth.** After a save, the page shows what
  Laravel returned, not what was typed, and all prices are calculated on the
  server.
