# The Shopify OAuth install, read from Telescope dumps

_Written 1 Oct 2026 from one real install on `tarun-dev-store-pnerqrpu`,
recorded with the temporary `dump()` calls marked `// DEBUG(dump)`. Every
value below is copied from that run. Times are UTC. Tokens are masked the way
the dumps masked them, and the app secret never appears anywhere._

This is the companion to [how-install-auth-works.md](how-install-auth-works.md),
which explains the same flow from the request, session and cookie side. This
one follows the **10 dumps** in order, so you can open Telescope → Dumps and
read along.

---

## 1. The whole thing in one paragraph

Your Laravel app wants to read a store's customers and products. For that it
needs a **key** from Shopify, called an **access token**. Shopify only hands
that key over once the store owner has clicked **Install**, and only to the
real app, proved by a **secret** that just Laravel and Shopify know. OAuth is
the back-and-forth that gets you there:

1. Laravel sends the owner to Shopify with a random **ticket** (`state`).
2. The owner clicks Install. Shopify sends them back with a one-time
   **voucher** (`code`) and a **seal** (`hmac`) proving the URL wasn't
   altered.
3. Laravel checks the seal and the ticket, then trades the voucher for the
   key, server to server, and saves the key in MySQL.

---

## 2. The values, and who makes each one

| Value                                 | Made by                                 | How it's made                                                              | What it's for                                                                     | Lifetime                         |
| ------------------------------------- | --------------------------------------- | -------------------------------------------------------------------------- | --------------------------------------------------------------------------------- | -------------------------------- |
| **`client_id`** (API key) `61c0dd61…` | Shopify, once, when the app was created | Fixed, from the Dev Dashboard                                              | Says _which app_ is asking. Not a secret                                          | Forever                          |
| **`client_secret`** `shpss_…`         | Shopify, once                           | Fixed, from the Dev Dashboard. Lives only in `.env`                        | Signs and proves things. Never leaves the server                                  | Until you rotate it              |
| **`state`** `73e53512…`               | **Laravel**, in `install()`             | `bin2hex(random_bytes(32))`: 32 random bytes, written as 64 hex characters | A ticket: proves the callback belongs to an install _this browser_ started        | Until the callback, then deleted |
| **`code`** `dfba90db…`                | **Shopify**, when Install is clicked    | Shopify's own random value                                                 | A voucher Laravel can swap for the access token, once                             | Single use, ~minutes             |
| **`hmac`** `dae42989…`                | **Shopify**, on the callback URL        | HMAC-SHA256 of the other URL parameters, keyed with `client_secret`        | A tamper seal: proves the URL came from Shopify, unchanged                        | One request                      |
| **`timestamp`** `1790868991`          | Shopify                                 | Unix time of the redirect (15:36:31)                                       | Part of what the `hmac` signs                                                     | One request                      |
| **`host`** `YWRtaW4u…`                | Shopify                                 | Base64 of `admin.shopify.com/store/tarun-dev-store-pnerqrpu`               | Tells an embedded app which admin it's in. Also signed by `hmac`                  | One request                      |
| **access token** `shpua_81…`          | Shopify, in the token exchange          | Shopify's own                                                              | **The key.** Sent as `X-Shopify-Access-Token` on every Admin API call             | 1 hour (`expires_in: 3599`)      |
| **refresh token** `shprt_ef…`         | Shopify, in the same answer             | Shopify's own                                                              | Gets a new access token when the old one runs out, without asking the owner again | 90 days (`7775999` s)            |

Laravel makes only one of these values itself: `state`. Shopify makes the
`code`, the `hmac` and both tokens. Laravel's job is to **check** what it's
given and **guard** what it receives.

---

## 3. The flow in one picture

```
 Browser                    Laravel (/auth, /auth/callback)          Shopify
    │                                 │                                 │
    │ GET /auth?shop=tarun-dev…       │                                 │
    │────────────────────────────────▶│ dumps 1–2                       │
    │                                 │ make state, save it in session  │
    │ 302 → /admin/oauth/authorize    │                                 │
    │◀────────────────────────────────│                                 │
    │      ?client_id&scope&redirect_uri&state                          │
    │──────────────────────────────────────────────────────────────────▶│
    │                         consent screen: owner clicks "Install"    │
    │ 302 → /auth/callback?code&hmac&host&shop&state&timestamp          │
    │◀──────────────────────────────────────────────────────────────────│
    │────────────────────────────────▶│ dumps 3–7                       │
    │                                 │ check hmac (seal), check state  │
    │                                 │ (ticket), then:                 │
    │                                 │ POST /admin/oauth/access_token  │
    │                                 │  client_id+client_secret+code ─▶│
    │                                 │◀─ access + refresh tokens ──────│
    │                                 │ dumps 8–9: save the shop row    │
    │ 302 → admin/apps/61c0dd…        │ dump 10                         │
    │◀────────────────────────────────│                                 │
```

The browser carries the merchant between Laravel and Shopify, but it **never
sees the tokens**. The token exchange is a direct call from Laravel to
Shopify. The browser only ever sees the `code`, which is useless without the
secret.

---

## 4. Walking through the dumps

### Request 1: `GET /auth?shop=tarun-dev-store-pnerqrpu.myshopify.com`

You open this URL in a normal browser tab. Code:
`ShopifyOAuthController::install()`.

#### Dump 1: Laravel builds the OAuth service

```
"step" => "[container] AppServiceProvider: built OAuthService"
"shopify config" => [
  "api_key" => "61c0dd61069efbd97987f0dfdb4a972d"
  "api_secret (masked)" => "shpss_********************************"
  "scopes" => "read_customers,read_products"
  "app_url" => "https://celebrity-mails-comp-distant.trycloudflare.com"
  "api_version" => "2026-07"
]
```

**What happened:** before the controller can run, Laravel's service container
builds `OAuthService`, which the controller asks for in its constructor. The
recipe is in `AppServiceProvider::register()`, which reads the settings from
`.env` through `config/shopify.php`.

**In plain words:** the app gets its ID card (`api_key`), its private stamp
(`api_secret`), the list of permissions it'll ask for (`scopes`) and its own
public address (`app_url`). If any of these is missing, the app stops here
with a clear error instead of failing confusingly later.

#### Dump 2: the ticket is made and the owner is sent to Shopify

```
"step" => "1. install: nonce saved, redirecting to Shopify consent screen"
"shop" => "tarun-dev-store-pnerqrpu.myshopify.com"
"state" => "73e53512c0be3815160728dea309f3c8bf0a4694eaa1bd9b9e79a1c3201b95a6"
"session[shopify_oauth]" => [
  "state" => "73e53512c0be…b95a6"
  "shop"  => "tarun-dev-store-pnerqrpu.myshopify.com"
]
"authorize_url" => "https://tarun-dev-store-pnerqrpu.myshopify.com/admin/oauth/authorize
                    ?client_id=61c0dd61069efbd97987f0dfdb4a972d
                    &scope=read_customers%2Cread_products
                    &redirect_uri=https%3A%2F%2Fcelebrity-mails-comp-distant.trycloudflare.com%2Fauth%2Fcallback
                    &state=73e53512c0be…b95a6"
```

**What happened, in order:**

1. **The shop name is checked.** `ShopDomain::tryFrom()` accepts only
   `something.myshopify.com`. A value like `evil.com` or
   `shop.myshopify.com.evil.com` gets a 400. That matters because the next
   step sends the merchant to this address.
2. **The ticket (`state`) is made:** `bin2hex(random_bytes(32))`.
   `random_bytes` asks the operating system for 32 bytes that can't be
   predicted, and `bin2hex` writes them as the 64 characters you see. Nobody,
   not even someone who has watched a thousand earlier installs, can guess the
   next one.
3. **One half of the ticket is kept.** `{state, shop}` goes into the Laravel
   session (the `sessions` table in MySQL, linked to this browser by a
   cookie). The shop is stored too, so a ticket issued for one store can't be
   used for another.
4. **The other half leaves in the URL.** `OAuthService::authorizeUrl()` builds
   Shopify's consent-screen address with four parameters:
   - `client_id`: which app is asking
   - `scope`: what it wants to read (`read_customers,read_products`)
   - `redirect_uri`: where Shopify should send the owner back. Built from
     `SHOPIFY_APP_URL`, never from the incoming request, because it has to
     match the Dev Dashboard **character for character**
   - `state`: the ticket

Laravel answers **302**, and the browser goes to Shopify.

#### Meanwhile, at Shopify

Shopify shows the consent screen: _"Wholesale Tiers wants to view customers
and products"_. The owner clicks **Install**. Shopify then:

- makes a **one-time `code`** for this app and this store,
- notes the time (`timestamp=1790868991`, i.e. 15:36:31),
- adds `host`, the admin address in Base64,
- **signs all of it** with your `client_secret` to make the `hmac`,
- sends the browser to your `redirect_uri` with everything attached.

---

### Request 2: `GET /auth/callback?code=…&hmac=…&host=…&shop=…&state=…&timestamp=…`

Code: `ShopifyOAuthController::callback()`. Eight dumps, one request. **Every
check has to pass before the `code` is used.**

#### Dumps 3 and 4: services built

```
"step" => "[container] AppServiceProvider: built OAuthService"
"step" => "[container] AppServiceProvider: built OAuthHmacVerifier"
```

A new request, so the container builds `OAuthService` again, plus
`OAuthHmacVerifier`, which `callback()` asks for as a method parameter. Both
get the same `.env` settings as in dump 1.

#### Dump 5: what Shopify sent back

```
"step" => "2. callback: Shopify redirected back"
"query" => [
  "code"      => "dfba90dbcf900934cd6ce4d8f55ffe96"
  "hmac"      => "dae42989d78c26362e6d1d837390f999fa944d41d7b6aa20e053618c332cdf25"
  "host"      => "YWRtaW4uc2hvcGlmeS5jb20vc3RvcmUvdGFydW4tZGV2LXN0b3JlLXBuZXJxcnB1"
  "shop"      => "tarun-dev-store-pnerqrpu.myshopify.com"
  "state"     => "73e53512c0be3815160728dea309f3c8bf0a4694eaa1bd9b9e79a1c3201b95a6"
  "timestamp" => "1790868991"
]
```

**In plain words:** the owner is back, carrying an envelope from Shopify. The
`state` in it is the same ticket Laravel gave out in dump 2. That's expected,
but it isn't checked yet. **Nothing in this URL is trusted yet.** Anyone can
type a URL into a browser, so first Laravel checks it really came from
Shopify.

#### Dump 6: checking the seal (`hmac`)

```
"step" => "3. hmac: re-signing the query with the app secret"
"signed message (sorted, without hmac)" =>
    "code=dfba90dbcf900934cd6ce4d8f55ffe96
     &host=YWRtaW4uc2hvcGlmeS5jb20vc3RvcmUvdGFydW4tZGV2LXN0b3JlLXBuZXJxcnB1
     &shop=tarun-dev-store-pnerqrpu.myshopify.com
     &state=73e53512c0be3815160728dea309f3c8bf0a4694eaa1bd9b9e79a1c3201b95a6
     &timestamp=1790868991"
"hmac from Shopify" => "dae42989d78c26362e6d1d837390f999fa944d41d7b6aa20e053618c332cdf25"
"hmac we computed"  => "dae42989d78c26362e6d1d837390f999fa944d41d7b6aa20e053618c332cdf25"
"match" => true
```

Code: `OAuthHmacVerifier::verify()`. This is the most important check, so here
it is slowly.

**What an HMAC is:** a fingerprint of a message that only someone holding a
secret can make. Change one character of the message, or use a different
secret, and the fingerprint comes out completely different. `SHA256` is the
recipe, and the result is always 64 hex characters.

**How Shopify made it:**

```
hmac = HMAC-SHA256( message = every parameter except hmac, sorted A→Z,
                              joined as  key=value&key=value…,
                    key     = client_secret )
```

**How Laravel checks it:** it repeats exactly the same recipe:

1. Take the query parameters and **remove `hmac`** (a seal can't seal itself).
2. **Sort by name**: `code`, `host`, `shop`, `state`, `timestamp`.
3. **Join** them as a query string. That gives the "signed message" above.
4. `hash_hmac('sha256', message, SHOPIFY_API_SECRET)` gives "hmac we
   computed".
5. **Compare** with `hash_equals()`. Both are `dae42989…2cdf25`, so
   **match = true**.

**Why this proves anything:** only Shopify and your server know
`client_secret`. If someone edited the URL (swapped the `shop`, say), the
message would change and the fingerprints wouldn't match. If someone made the
whole URL up, they couldn't produce the right fingerprint without the secret.

**Why `hash_equals` and not `===`:** `===` stops at the first wrong
character, so a wrong guess that starts with 10 correct characters takes
slightly longer to reject than one that starts with 1. By timing many
attempts, an attacker could work the seal out character by character.
`hash_equals` always takes the same time.

**Two details that broke things before:**

- A wrong `SHOPIFY_API_SECRET` gives a correct message but a different
  fingerprint, and a **403 Invalid HMAC**. That's exactly what happened
  earlier the same day, before the secret in `.env` was corrected.
- `bootstrap/app.php` switches off Laravel's input clean-up (trimming spaces,
  turning `''` into `null`) for `/auth/callback`. Shopify signed the values
  _exactly as sent_, so even a trimmed space would break the match.

#### Dump 7: checking the ticket (`state`)

```
"step" => "4. callback: comparing state with the nonce from step 1"
"session nonce (pulled, now deleted)" => [
  "state" => "73e53512c0be…b95a6"
  "shop"  => "tarun-dev-store-pnerqrpu.myshopify.com"
]
"state from Shopify" => "73e53512c0be…b95a6"
"state matches" => true
"shop matches"  => true
```

**What happened:** Laravel takes its half of the ticket back out of the
session with `session()->pull()`, which **reads and deletes it in one go**.
Then it checks:

- the `state` in the URL equals the saved one (`hash_equals` again), and
- the `shop` in the URL equals the shop saved with it.

**Why the seal isn't enough on its own:** the `hmac` proves Shopify made this
URL. It doesn't prove the URL belongs to _this_ browser's install. Without
the ticket, an attacker could start an install for _their own_ store, stop
before the callback, and trick you into opening their genuine Shopify-signed
callback URL. Your browser would then link their store to your session.
That's a CSRF attack, and the ticket stops it, because only your browser's
session holds the matching half.

**Why `pull` and not `get`:** the ticket is deleted as it's read, so the same
callback URL can never be used twice (a "replay"). Reload it and you get
**403 Invalid or expired OAuth state**.

#### Dump 8: cashing the voucher (`code`) for the key

```
"step" => "5. service: traded the code for a token"
"POST" => "https://tarun-dev-store-pnerqrpu.myshopify.com/admin/oauth/access_token"
"sent" => [
  "client_id"     => "61c0dd61069efbd97987f0dfdb4a972d"
  "client_secret" => "[hidden]"
  "code"          => "dfba90dbcf900934cd6ce4d8f55ffe96"
  "expiring"      => "1"
]
"status" => 200
"response (tokens masked)" => [
  "access_token"             => "shpua_81******************************"
  "scope"                    => "read_customers,read_products"
  "expires_in"               => 3599
  "refresh_token"            => "shprt_ef******************************"
  "refresh_token_expires_in" => 7775999
]
```

Code: `OAuthService::install()`. **This call goes from the Laravel container
straight to Shopify.** The browser isn't involved and never sees the answer.

**What was sent:**

- `client_id` + `client_secret`: "I am the real app." This is why a stolen
  `code` is worthless: cashing it needs the secret.
- `code`: the voucher from dump 5. Shopify accepts it **once**.
- `expiring=1`: "give me a token that expires, plus a refresh token".
  Shopify's current recommendation. A leaked token that dies in an hour does
  far less damage than one that lives forever.

**What came back:**

- **`access_token`** (`shpua_…`): the key, valid for `3599` seconds (one hour).
- **`refresh_token`** (`shprt_…`): valid for `7775999` seconds (90 days).
  When the access token is about to run out, Laravel trades this for a new
  pair without bothering the owner. That's `OAuthService::freshAccessToken()`,
  which runs before every Admin API call.
- **`scope`**: what the owner actually granted.

The dump masks both tokens after 8 characters. Telescope's own masking
settings don't apply to dumps, and dumps are stored in MySQL as plain text.

#### Dump 9: saving the shop

```
"step" => "6. service: shop row saved (token encrypted in the database)"
"granted scopes" => "read_customers,read_products"
"missing scopes" => []
"new row?" => true
"shop" => [
  "id" => 4
  "shop_domain" => "tarun-dev-store-pnerqrpu.myshopify.com"
  "scopes" => "read_customers,read_products"
  "installed_at"             => 2026-10-01 15:36:33
  "uninstalled_at"           => null
  "access_token_expires_at"  => 2026-10-01 16:36:32   (now + 3599 s)
  "refresh_token_expires_at" => 2026-12-30 15:36:32   (now + 7775999 s)
]
```

**What happened, in order:**

1. **The tokens are checked:** there must be an `access_token`, or Laravel
   throws an `OAuthException`.
2. **Every scope asked for must have been granted** (`missingScopes()`).
   Here nothing is missing (`[]`). A `write_` scope counts as also granting its
   `read_` scope.
3. **Seconds become dates:** `expires_in: 3599` is stored as
   `access_token_expires_at = now + 3599 s`. A fixed date is easy to compare
   later. A countdown isn't.
4. **`Shop::updateOrCreate()`**, keyed on `shop_domain`. If the store already
   has a row (a reinstall), it's updated and keeps its tiers. Here
   `new row? => true`: there was no row for this store, so row `id 4` was
   created.
5. **The tokens are encrypted on the way in.** The `Shop` model's `encrypted`
   cast scrambles `access_token` and `refresh_token` with `APP_KEY` before
   they reach MySQL. Someone reading the database directly sees ciphertext,
   not `shpua_…`.

**If anything went wrong in dumps 8–9** (Shopify unreachable, the `code`
refused, a scope missing), `OAuthService` throws an **`OAuthException`**. The
controller catches _that type only_, logs it, and answers **403 Shopify did
not grant access**. Because it's a dedicated exception type, an unrelated
failure like a database error isn't hidden behind the same message.

#### Dump 10: into the admin

```
"step" => "7. callback: installed, redirecting into the admin"
"admin_app_url" => "https://tarun-dev-store-pnerqrpu.myshopify.com/admin/apps/61c0dd61069efbd97987f0dfdb4a972d"
```

Laravel answers **302** to the app's page inside the Shopify admin. The admin
loads your tunnel URL in an iframe, and the React app starts. From then on
there are no `state`, `code` or `hmac` values any more. Every `/api` call is
authenticated with a short-lived **ID token** instead. That's a separate
mechanism, explained in [how-api-auth-works.md](how-api-auth-works.md).

---

## 5. Every check, and what it stops

| Check                              | Where                       | Dump | Without it…                                                                        | Failure answer                     |
| ---------------------------------- | --------------------------- | ---- | ---------------------------------------------------------------------------------- | ---------------------------------- |
| Shop name format                   | `ShopDomain::tryFrom`       | 2, 5 | An attacker could make `/auth?shop=evil.com` send merchants to a fake consent page | 400                                |
| `hmac` seal                        | `OAuthHmacVerifier::verify` | 6    | Anyone could hand-write a callback URL, or change the `shop` in a real one         | 403 Invalid HMAC                   |
| `state` ticket matches the session | `callback()`                | 7    | CSRF: someone else's install could be finished in your browser                     | 403 Invalid or expired OAuth state |
| Ticket deleted on use (`pull`)     | `callback()`                | 7    | The same callback URL could be replayed                                            | 403 on the second try              |
| Ticket's shop = URL's shop         | `callback()`                | 7    | A ticket for store A could finish an install for store B                           | 403                                |
| `code` cashed with the secret      | `OAuthService::install`     | 8    | A `code` seen in a URL or browser history could be cashed by someone else          | Shopify refuses → 403              |
| All scopes granted                 | `missingScopes()`           | 9    | The app would install and then fail on its first customer query                    | 403                                |
| Tokens encrypted at rest           | `Shop` model cast           | 9    | A database dump would leak working store keys                                      | —                                  |

---

## 6. Which file does what

| File                                                   | Role in the install                                                                                                              |
| ------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------- |
| `routes/web.php`                                       | Maps `GET /auth` → `install()` and `GET /auth/callback` → `callback()`. In the `web` group, so they get a session for the ticket |
| `app/Http/Controllers/Auth/ShopifyOAuthController.php` | The two steps. Makes and checks the ticket, calls the HMAC check, hands the `code` to the service, decides the HTTP answer       |
| `app/Services/Shopify/OAuthHmacVerifier.php`           | The seal check. Pure PHP, no framework calls, so it unit-tests directly                                                          |
| `app/Services/Shopify/OAuthService.php`                | Builds the consent URL, swaps `code` → tokens, checks scopes, saves the shop. Later keeps the token fresh                        |
| `app/Services/Shopify/OAuthException.php`              | An empty class whose _type_ tells the controller "Shopify said no", as opposed to "something else broke"                         |
| `app/Support/ShopDomain.php`                           | The only way to get a "checked" shop name                                                                                        |
| `app/Providers/AppServiceProvider.php`                 | Builds the services from `.env`, and fails loudly if a setting is missing                                                        |
| `bootstrap/app.php`                                    | Switches off input clean-up for `/auth/callback`, so the HMAC sees the exact values                                              |
| `app/Models/Shop.php`                                  | The `shops` row. Encrypts the tokens                                                                                             |

---

## 7. Run it yourself

```bash
# record dumps without keeping the Dumps tab open, and skip /api noise (already in .env)
#   TELESCOPE_DUMP_WATCHER_ALWAYS=true
#   DEBUG_DUMP_API=false

docker compose exec app php artisan telescope:clear   # start from an empty list
cloudflared tunnel --url http://localhost:8000          # if not already running
```

Then open, in a normal browser tab (not inside the admin):

```
https://<tunnel>/auth?shop=tarun-dev-store-pnerqrpu.myshopify.com
```

Then open **http://localhost:8000/telescope/dumps**. The 10 dumps appear
newest first, so read from the bottom up. They'll match the walkthrough
above, with new random values.

**Experiments worth trying:**

- **Reload the callback URL** after a successful install. Dump 7 shows the
  session nonce as `null`, and you get 403. That's the replay protection.
- **Change one character of `shop` in a callback URL.** Dump 6 shows the
  signed message changed and `match => false`, and you get 403.
- **Put a wrong `SHOPIFY_API_SECRET` in `.env`.** Dump 6 shows the same
  message with a different computed fingerprint.

---

## 8. Words used here

| Word                         | Meaning                                                                                                                                |
| ---------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| **OAuth**                    | The standard way for an app to get permission to act on someone's account without learning their password                              |
| **Scope**                    | One permission, e.g. `read_customers`                                                                                                  |
| **Nonce / `state`**          | A random value used once. Here: the ticket that ties the callback to the browser that started the install                              |
| **HMAC**                     | A fingerprint of a message that only the holder of a secret can make. Proves who made it and that it wasn't changed                    |
| **SHA-256**                  | The fingerprint recipe used inside the HMAC                                                                                            |
| **Authorization code**       | Shopify's one-time voucher, worthless without the client secret                                                                        |
| **Access token**             | The key for the Admin API. Here it expires after 1 hour                                                                                |
| **Refresh token**            | Gets a new access token without the owner. Here it lasts 90 days                                                                       |
| **Offline token**            | A token tied to the _store_, not to whoever is logged in, so it works even when nobody is using the admin. That's what this app stores |
| **CSRF**                     | Tricking a browser into finishing an action someone else started. The `state` check prevents it                                        |
| **Replay**                   | Sending a captured request again. Deleting the ticket on use prevents it                                                               |
| **Constant-time comparison** | `hash_equals`: takes the same time however many characters match, so timing reveals nothing                                            |
