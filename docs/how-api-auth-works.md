# After install: how each click is authenticated

_Written 30 Sep 2026 from the code, plus one live read of the `shops` row on
`tarun-dev-store-pnerqrpu`. Long random values are cut short with `…`._

This is the second half of [how-install-auth-works.md](how-install-auth-works.md).
That one covers the install: how Laravel gets the key. This one covers
everything after it: how each click proves which store it came from, how the
key stays alive, and what the merchant sees when it can't.

---

## 1. The short version

Two tokens do all the work. They travel in different directions and never
meet.

|                    | **ID token**                                 | **Access token**                                  |
| ------------------ | -------------------------------------------- | ------------------------------------------------- |
| Travels            | Browser → Laravel                            | Laravel → Shopify                                 |
| Answers            | "Which store is this click from?"            | "Is Laravel allowed to read this store's data?"   |
| Made by            | App Bridge, in the browser, when needed      | Shopify: at install, then at every refresh        |
| Lives              | About 60 seconds                             | 1 hour. A refresh token (90 days) gets a new one  |
| Stored             | Nowhere. Made when needed, then thrown away  | The `shops` row in MySQL, encrypted               |
| Is it a JWT?       | Yes. Anyone can read it; nobody can edit it  | No. A random string only Shopify can look up      |
| Reaches the browser | It starts there                             | **Never**                                         |

Think of an office building. The ID token is a visitor badge printed at the
front desk each time you walk in. It says who you are, and it stops working
in a minute. The access token is the key to the storeroom. It stays in the
manager's safe (MySQL), visitors never touch it, and the manager swaps it for
a new one every hour.

---

## 2. How App Bridge gets into the page

The app has one HTML page, [app.blade.php](../resources/views/app.blade.php).
Two lines in its `<head>` set up the whole browser side:

```html
<meta name="shopify-api-key" content="61c0dd61…">
<script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
```

- The meta tag is the **public** client ID. App Bridge needs it to know which
  app it belongs to. It's safe in a page, like a username. The secret never
  goes here.
- The script is **App Bridge**: Shopify's small library that lets an app
  inside the admin's iframe talk to the admin around it. (An iframe is a web
  page shown inside another web page.)

The script must be the **first** script, and it must not be `async`, `defer`
or a module. The reason: App Bridge **replaces the browser's `fetch` function
with its own version**, and its version adds the ID token to every request to
our own server. If our React code ran first, some of its requests could leave
before the swap, with no token.

That's why [lib/api.js](../resources/js/lib/api.js) sets no `Authorization`
header. It looks like a bug. It isn't: by the time `api()` calls `fetch`, it's
App Bridge's `fetch`, and that adds the header.

[App.jsx](../resources/js/App.jsx) adds one guard. Opened on its own, outside
the admin, there's no admin to make ID tokens, so every `/api` call would fail.
The app shows "Open this app from your Shopify admin" instead.

---

## 3. Moving between screens authenticates nothing

This is the part that surprises people.

Customers, Settings and Preview are **not** separate pages as far as Laravel
knows. React Router swaps what's on screen and changes the URL inside the
iframe without asking the server anything. No request, no token, no check.

Authentication happens **per `fetch`, not per screen.** A screen change only
matters because the new screen usually fetches its data when it appears.

| What you do                           | What reaches Laravel                                   | Does Laravel then call Shopify? |
| ------------------------------------- | ------------------------------------------------------ | ------------------------------- |
| Open the app in the admin             | `GET /`, the HTML page. No token: the page holds no secrets | No                          |
| Customers screen appears              | `GET /api/tiers` and `GET /api/customers`              | `/customers` yes, `/tiers` no   |
| Change the tier filter, or Next page  | `GET /api/customers?tier=…&after=…`                    | Yes                             |
| Click "Tier settings"                 | Nothing. React Router only                             | —                               |
| Settings screen appears               | `GET /api/tiers`                                       | No. Tiers live in MySQL         |
| Save, add or delete a tier            | `PUT`, `POST` or `DELETE` on `/api/tiers`              | No                              |
| Preview screen appears                | `GET /api/products?limit=1`, then `GET /api/preview?product_id=…` | Yes                  |
| Click "Choose product"                | Nothing. The admin draws its own picker (`shopify.resourcePicker`) | —                   |

Every row that reaches `/api` goes through the same checks (section 4), each
with its own ID token.

**A screen left open for an hour still works.** The token is attached when a
request is made, not when the page loaded. Its 60-second life never runs out
on an idle screen.

---

## 4. One API call, step by step

```
  Shopify admin (admin.shopify.com)
    └─ iframe: your app, with App Bridge loaded
         React:       api('/customers')  →  fetch('/api/customers')
         App Bridge:  adds  Authorization: Bearer <ID token>
                  │
                  │   no cookie, just the token
                  ▼
  Laravel: VerifyShopifySessionToken  (on every /api route)
         1. signed with our API secret?   HS256 signature        ─┐
         2. still fresh?                  exp / nbf, 5 s leeway   │ fail → 401
         3. issued for our app?           aud = our client ID     │  + retry header
         4. which shop?                   dest, and iss agrees   ─┘  (section 5)
         5. has that shop installed us?   [DB] shops row         ── fail → 401, no retry header
                  │
                  │   puts the Shop row on the request
                  ▼
  Controller → ShopifyGraphQLClient
         token from OAuthService::freshAccessToken()   (section 6)
         X-Shopify-Access-Token: <access token>
                  │
                  ▼
  Shopify Admin API → JSON → Laravel → JSON shaped by a Resource → React
                                       (no tokens, ever)
```

Checks 1–4 live in [SessionTokenVerifier.php](../app/Services/Shopify/SessionTokenVerifier.php).
It has no database and no framework calls, so it's easy to test. Check 5 is in
[VerifyShopifySessionToken.php](../app/Http/Middleware/VerifyShopifySessionToken.php).

The details that matter:

- **The algorithm is fixed in our code (`HS256`), not read from the token.**
  A forged token whose header says `"alg": "none"` is refused.
- **The shop comes from `dest`, inside the signed token.** Never from
  `?shop=` in the URL. App Bridge also keeps `apiKey`, `shop` and `host` in
  `sessionStorage` under `app-bridge-config`, and you can edit that `shop` in
  DevTools. Laravel never reads it. Change one character of the token itself
  and check 1 fails.
- **The shop is put on `$request->attributes`, not read as `$request->shop`.**
  The second looks the same, but it reads `?shop=` from the URL first. That
  would let the browser choose the shop.
- **Every failure answers the same `401 Unauthorized.`** The real reason goes
  to the log. Someone probing the API learns nothing about which check failed.
- **The middleware is attached to the whole `api` group** in
  [bootstrap/app.php](../bootstrap/app.php), not route by route. A new route
  can't be left unprotected by forgetting it.

**Why no cookie?** The app runs in an iframe on `admin.shopify.com`, a
different site from your app. Browsers increasingly block cookies there. A
token in a header works everywhere.

**What goes in and out on one API call:**

| Direction         | Contents                                                                                      |
| ----------------- | --------------------------------------------------------------------------------------------- |
| React → Laravel   | `GET /api/customers?…` with `Authorization: Bearer eyJ…` (the ID token)                       |
| Laravel → Shopify | `POST /admin/api/2026-07/graphql.json` with `X-Shopify-Access-Token: …` and the GraphQL query |
| Shopify → Laravel | JSON: the customers, `pageInfo`, and the query's cost                                         |
| Laravel → React   | JSON shaped by `CustomerResource`. No tokens, ever                                            |

---

## 5. When the ID token fails: App Bridge retries once

An ID token lives about a minute, so now and then one expires on its way to
Laravel. The fix is built into the 401.

```
  App Bridge ── GET /api/customers (token just expired) ──▶ Laravel
                                                              check 2 fails
  App Bridge ◀── 401 + X-Shopify-Retry-Invalid-Session-Request: 1 ──
     sees the header
     gets a fresh ID token
     sends the same request again, once
  App Bridge ── GET /api/customers (fresh token) ─────────▶ Laravel
  App Bridge ◀── 200 + customers ───────────────────────────
```

React never sees the first 401. From its side, the request just took a little
longer.

The middleware sends that header for failures 1–4: a bad or missing token,
which a new one may fix. It deliberately **leaves it off** for failure 5. There
the token was fine; the shop simply has no row in `shops`. A fresh token can't
install the app, so a retry would only fail the same way twice.

---

## 6. The access token's life: one hour, renewed by Laravel

Install asks Shopify for an **expiring** token (`expiring=1`, step 5 of the
install doc). So the `shops` row holds two tokens. Live values from your dev
store, in UTC as stored:

| Column                     | Value                   | Meaning                                    |
| -------------------------- | ----------------------- | ------------------------------------------ |
| `access_token`             | `eyJpdiI6…` (encrypted) | The key. Dies 1 hour after it's issued     |
| `access_token_expires_at`  | `2026-09-30 15:17:13`   | 1 hour after the last refresh (14:17:13)   |
| `refresh_token`            | `eyJpdiI6…` (encrypted) | Gets a new key. Dies after 90 days         |
| `refresh_token_expires_at` | `2026-12-29 14:17:13`   | 90 days after the last refresh             |

Nobody — not React, not the merchant — does anything to keep the key alive.
Every Admin API call first asks
[OAuthService::freshAccessToken()](../app/Services/Shopify/OAuthService.php)
for the token:

```
  freshAccessToken(shop)
     │
     ├─ more than 60 s left? ─── yes → use it as is  (the usual case)
     │
     └─ no → take this shop's lock  (Cache::lock, waits up to 10 s)
               │
               ├─ reload the row from MySQL
               ├─ still expiring?
               │     no  → another request just refreshed it. Use theirs
               │     yes → POST /admin/oauth/access_token, grant_type=refresh_token
               │           ← a new access token AND a new refresh token
               │           save both, encrypted
               └─ release the lock → return the token
```

Five details, each there for a reason:

1. **Refresh 60 seconds early** (`REFRESH_MARGIN_SECONDS`). A token with five
   seconds left when it's handed out could die on the way to Shopify.
2. **Save both new tokens.** Every refresh also brings a new refresh token,
   and the old one stops working once the new one is used. Save only the
   access token, and the next refresh fails.
3. **One token request per shop at a time: the lock.** Shopify's docs warn:
   _"Acquiring a token and refreshing one each retire the other's result, so
   one of the two tokens your app receives is already invalid when it
   arrives."_ The lock makes a second request wait its turn.
4. **Reload the row inside the lock.** This is the subtle one. Without it,
   the lock only makes two refreshes take turns: request B waits, then
   refreshes anyway, and retires what request A just saved. With the reload,
   B sees A's fresh token and does nothing.
   `test_no_second_refresh_when_another_request_already_refreshed` in
   [AccessTokenRefreshTest.php](../tests/Feature/Services/Shopify/AccessTokenRefreshTest.php)
   proves it.
5. **Ask for the token on every attempt.** `ShopifyGraphQLClient::send()`
   calls `freshAccessToken()` each time, including retries after a throttle
   wait. A retry that waited 1 + 2 seconds can't go out with a token that
   expired during the wait.

**React never knows any of this happened.** It asked for customers and got
customers.

---

## 7. When the token can't be renewed: the Reconnect button

Sometimes renewing is impossible. The refresh token has expired (nobody used
the app for 90 days) or been replaced, or Shopify revoked the access token.
No retry helps. Shopify's advice for apps like this one is to send the
merchant through OAuth again.

**What triggers it:**

| Where it's noticed                            | What Shopify said                                 | Why it's final                        |
| --------------------------------------------- | ------------------------------------------------- | ------------------------------------- |
| `OAuthService`, refreshing                    | `401 {"error":"invalid_request"}`                 | Refresh token expired or replaced     |
| `OAuthService`, before refreshing             | Nothing. The row has an expiry but no refresh token | Nothing to refresh with             |
| `ShopifyGraphQLClient`, calling the Admin API | `401`                                             | The token was fresh, so it was revoked |

All three throw
[ReauthorizationRequiredException](../app/Services/Shopify/ReauthorizationRequiredException.php),
and [bootstrap/app.php](../bootstrap/app.php) turns it into:

```
HTTP 403
{
  "message": "Shopify no longer accepts this app's connection to your store. Reconnect to continue.",
  "reauthorize_url": "https://<SHOPIFY_APP_URL>/auth?shop=tarun-dev-store-pnerqrpu.myshopify.com"
}
```

- **403, not 500.** Nothing on the server is broken, and the merchant can
  fix it.
- **Not 401.** A 401 means "bad ID token", and this ID token was fine.
- **The technical reason still goes to the log**, for example "Shopify
  refused the refresh token for tarun-dev-store-pnerqrpu.myshopify.com." The
  merchant gets a plain sentence instead.

**What is deliberately _not_ treated this way:** Shopify answering with a
5xx, or not answering at all. Those say nothing about the refresh token, which
may be fine. They stay an ordinary 500 (`OAuthException`), and the next
request simply tries again. Sending a merchant through OAuth because Shopify
had a bad minute would be wrong.
`test_a_passing_refresh_failure_is_not_mistaken_for_a_dead_token` guards that
line.

**On the React side:**

```
  api.js          finds reauthorize_url in the 403 body, puts it on the Error
                        │
  Customers.jsx   the error banner shows the message, and
  Preview.jsx     reconnectAction(error) adds a "Reconnect" button
                        │
  merchant clicks → window.open(reauthorize_url, '_top')
                        │
  App Bridge      leaves the admin and loads /auth in the whole tab
                        │
  Laravel /auth   the same OAuth flow as the install doc, steps 1–7
                  → the shops row gets new tokens
                  → back into the admin, with the app loaded and working
```

Two details in [lib/api.js](../resources/js/lib/api.js):

- **`'_top'`, because OAuth can't run inside the iframe.** Shopify's consent
  screen refuses to load in a frame. App Bridge's docs give
  `open(url, '_top')` as the way to leave the admin and load a page in the
  whole tab.
- **The URL is absolute, and Laravel builds it.** App Bridge treats a
  relative URL such as `/auth?shop=…` as a route inside the app, which would
  load in the iframe again. `OAuthService::reauthorizeUrl()` builds it from
  `SHOPIFY_APP_URL`, like the OAuth redirect URI. If the tunnel address has
  changed since you set that, the button leads nowhere, just as an install
  would.

The merchant may see the consent screen again. If so, they click Install,
exactly like the first time.

The Settings screen never shows the button. `/api/tiers` only reads MySQL, so
it can't hit this error.

**Not covered yet.** When the middleware finds no row in `shops` (check 5), it
still answers a bare 401, with no Reconnect button. Nothing in this app marks
a shop as uninstalled (there are no webhooks), so this happens only when the
row is missing — for example after `migrate:fresh` on your dev database. Open
`/auth?shop=…` yourself to recover.

**One difference from Shopify's advice.** Shopify also says to clear the
stored token. This app keeps it: until the merchant reconnects, each request
tries the dead refresh token once more and gets the same 403. Harmless, but
it's one wasted call to Shopify per request.

---

## 8. Three strings that start with `eyJ`. Only one is a JWT.

| Where you see it                                 | Starts with  | What it really is                                  | jwt.io reads it? |
| ------------------------------------------------ | ------------ | -------------------------------------------------- | ---------------- |
| `laravel-session` cookie                         | `eyJpdiI6`   | Laravel's encryption envelope around a session ID  | No               |
| `access_token` and `refresh_token` in `shops`    | `eyJpdiI6`   | The same envelope, around Shopify's token          | No               |
| `Authorization: Bearer …` on an `/api` request   | `eyJhbGciOi` | A JWT: header `.` payload `.` signature            | Yes              |

Why they all start alike: `eyJ` is what base64 turns `{"` into. Anything that
is base64-encoded JSON starts with `eyJ`. The next few characters tell them
apart:

- `eyJpdiI6` decodes to `{"iv":`. That's Laravel's encryption: an `iv`, a
  `value`, a `mac` and a `tag`. One long piece, no dots.
- `eyJhbGciOi` decodes to `{"alg":`. That's a JWT header naming its signing
  algorithm. Three pieces joined by two dots.

**The jwt.io error, explained.** The live `access_token` column starts
`eyJpdiI6IjFt` (`{"iv":"1m`), is 256 characters long, and contains **zero**
dots. jwt.io needs exactly two. That's the whole error.

Decrypting it wouldn't help either. Inside the envelope is Shopify's access
token, which is **opaque**: a random string with nothing readable inside.
Shopify checks it by looking it up on its own side. There's nothing to decode.

**To see a real JWT:** Firefox DevTools → Network → click any `/api/…` request
→ Request Headers → copy the value after `Bearer `, and paste it into jwt.io.
Among the claims you'll see the ones Laravel checks:

```json
{
  "iss": "https://tarun-dev-store-pnerqrpu.myshopify.com/admin",
  "dest": "https://tarun-dev-store-pnerqrpu.myshopify.com",
  "aud": "61c0dd61…",
  "exp": …,
  "nbf": …
}
```

jwt.io can't confirm the signature unless you give it the API secret.
**Don't paste the secret into a website.** Checking the signature is Laravel's
job (check 1). The token itself is fine to look at: it dies within a minute.

---

## 9. Try it: a normal refresh, then a Reconnect

Two experiments on the dev store, in `php artisan tinker`
(`docker compose exec app php artisan tinker`).

**A. A normal refresh. Harmless.**

```php
$shop = App\Models\Shop::first();
$shop->update(['access_token_expires_at' => now()->addSeconds(30)]);
```

Open the Customers screen. Customers load as normal. In Telescope → HTTP
Client, there's a `POST …/admin/oauth/access_token` with
`grant_type=refresh_token`, then the GraphQL call. Back in tinker,
`$shop->fresh()->access_token_expires_at` is an hour away again.

**B. A dead refresh token. Breaks it on purpose.**

This swaps the real refresh token for a fake one. Afterwards the only way
back is the Reconnect button, or opening `/auth?shop=…` yourself — which is
exactly what's being tested.

```php
$shop = App\Models\Shop::first();
$shop->update([
    'refresh_token' => 'shprt_deliberately_dead',
    'access_token_expires_at' => now()->subMinute(),
]);
```

Use `update()` in tinker, not SQL. The column is encrypted, so plain text
written by SQL would fail to decrypt: a different error from the one you want.

1. Open the Customers screen. Expect a red "Couldn't load customers" banner
   with the Reconnect sentence and a **Reconnect** button.
2. Telescope → Requests → `/api/customers`: status **403**, with
   `reauthorize_url` in the response. Telescope → HTTP Client: the refresh
   `POST` was answered **401**.
3. `storage/logs/laravel.log` has "Shopify refused the refresh token for
   tarun-dev-store-pnerqrpu.myshopify.com."
4. Click **Reconnect**. The whole tab leaves the admin and goes through
   `/auth`. If the consent screen appears, click Install.
5. You land back in the admin with the app working. In tinker,
   `$shop->fresh()->refresh_token_expires_at` is 90 days away again.

---

## 10. Words used in this document

- **App Bridge**: Shopify's JavaScript library that lets an app inside the admin's iframe talk to the admin. Here it adds the ID token to each `fetch`, and handles leaving the frame.
- **iframe**: a web page shown inside another web page. Your app is an iframe inside `admin.shopify.com`.
- **ID token (session token)**: a JWT that App Bridge makes to prove which store a request came from. Lives about a minute.
- **JWT**: a small signed package of JSON, in three parts joined by dots. Anyone can read it; nobody can change it without breaking the signature.
- **Access token**: the key Laravel sends to Shopify's Admin API for one store. Opaque, not a JWT. Here it lives one hour.
- **Refresh token**: a second key, good for 90 days, whose only use is getting a new access token. Replaced on every use.
- **Opaque token**: a random string with nothing readable inside. Only the server that issued it knows what it means.
- **Lock**: a flag only one request can hold at a time, so two requests can't do the same job at once.
- **React Router**: the library that swaps screens inside the React app without asking the server.

---

## 11. Saying it in an interview (about 40 seconds)

> The app uses two tokens that never meet. The browser proves which store a
> click came from with a short-lived ID token that App Bridge attaches to
> every fetch. Laravel checks its signature, audience and expiry, and reads
> the shop from inside the signed token, never from the URL. Laravel then
> calls Shopify with that store's access token, which never leaves the
> server. That token expires hourly, so Laravel refreshes it a minute early,
> under a per-shop lock, and reloads the row inside the lock so two requests
> can't both refresh and retire each other's tokens. If Shopify refuses the
> refresh token outright, the API answers 403 with a reconnect URL, and the
> UI offers a Reconnect button that leaves the iframe and runs OAuth again.
> A brief Shopify outage stays an ordinary error, so merchants aren't sent
> through OAuth for a blip.
