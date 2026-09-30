# How the Shopify install works, explained from a real run

_Written 29 Sep 2026 from one real install on `tarun-dev-store-pnerqrpu`.
Long random values are cut short with `…`. Times are IST._

---

## 1. The short version

Your app wants to read a store's customers and products. To do that it needs
a **key** from Shopify, called an access token.

Shopify won't hand that key to anyone who asks. The store owner has to say yes
first. **OAuth** is the standard back-and-forth that gets you from "no key" to
"key safely stored in MySQL". (OAuth is the standard way for an app to get
permission to act on someone's account without ever learning their password.)

Three values carry the whole process. Learn these three and the rest is detail:

- **`state`**: made by your app. Think of a ticket torn in half. You keep one
  half in MySQL. The other half leaves in the URL, Shopify holds it while the
  consent screen is open, and it comes back in the callback URL. The two
  halves must match.
- **`code`**: made by Shopify when the owner clicks Install. Think of a cheque
  made out to your app. Anyone can see it, but only you can cash it, because
  cashing it needs your secret.
- **access token**: the key itself. Shopify hands it straight to Laravel. It
  stays in Laravel and MySQL and never reaches the browser.

---

## 2. Two different questions. Don't mix them.

Most confusion about Shopify auth comes from mixing up two separate things:

|            | **A. The install** (sections 3–8)               | **B. Every API call afterwards** ([next doc][after])    |
| ---------- | ----------------------------------------------- | ------------------------------------------------------- |
| Question   | "Is this app allowed to read this store?"       | "Which store is this click coming from?"                |
| When       | Once per store (and again on reinstall)         | Every `fetch('/api/…')` from React                      |
| Proof      | `state` + `hmac` + `code` → access token        | An ID token (a signed JWT) that App Bridge attaches     |
| Cookies?   | Yes. The Laravel session cookie holds the state | No. None at all                                         |
| Code       | `ShopifyOAuthController`, `OAuthService`        | `VerifyShopifySessionToken`, `SessionTokenVerifier`     |
| End result | A row in `shops` holding the access token       | Laravel knows which `shops` row to use for this request |

**A gets Laravel the key. B tells Laravel whose key to use.**

[after]: how-api-auth-works.md

---

## 3. The players, and who holds what

Four players:

- **Browser**: Firefox. Carries the merchant from place to place and holds cookies.
- **Laravel**: your app, in Docker, reached through the cloudflared tunnel.
- **MySQL**: the `sessions` table (short-term) and the `shops` table (long-term).
- **Shopify**.

| Value                      | Example            | Who can see it                                           | How long it lives                                     |
| -------------------------- | ------------------ | -------------------------------------------------------- | ----------------------------------------------------- |
| API key (client ID)        | `61c0dd61…`        | Everyone. It's public, like a username                   | Forever                                               |
| API secret (client secret) | never shown        | Laravel and Shopify only                                 | Until you rotate it                                   |
| `APP_KEY`                  | never shown        | Laravel only. Encrypts cookies and the tokens in `shops` | Forever                                               |
| Session ID                 | `45N0nq…`          | Browser (encrypted inside the cookie), MySQL             | 2 hours of inactivity (`SESSION_LIFETIME=120`)        |
| `state`                    | `6902b36e…`        | Browser (in URLs), Shopify (during consent), MySQL       | Until the callback deletes it, or the session expires |
| `code`                     | made on Install    | Browser (in the callback URL)                            | Short. Works once                                     |
| `hmac`                     | made on Install    | Browser (in the callback URL)                            | One request                                           |
| Access token               | never shown        | Laravel and MySQL (encrypted). **Never the browser**     | 1 hour. The refresh token gets a new one              |
| Refresh token              | never shown        | Laravel and MySQL (encrypted). **Never the browser**     | 90 days. Replaced by a new one on every refresh       |
| ID token                   | made by App Bridge | Browser → Laravel, on every API call                     | About 60 seconds                                      |

---

## 4. The whole install in one picture

```
  BROWSER                          LARAVEL + MYSQL                          SHOPIFY
  Firefox                         your app, via tunnel
     │                                   │                                      │
 1   │── GET /auth?shop=tarun-dev… ─────▶│                                      │
     │                                   │ make state = 6902b36e…               │
     │                                   │ [DB] sessions ← state + shop         │
     │◀─ 302 → Shopify, state in URL ────│                                      │
     │ + Set-Cookie: laravel-session     │                                      │
     │                                   │                                      │
 2   │── GET …/admin/oauth/authorize?client_id…&state=6902b36e… ───────────────▶│
     │◀─ consent screen with the Install button ────────────────────────────────│
     │                                   │                                      │
 3   │── you click Install ────────────────────────────────────────────────────▶│
     │◀─ 302 → /auth/callback?code&hmac&shop&state&timestamp ───────────────────│
     │                                   │                                      │
 4   │── GET /auth/callback?… ──────────▶│                                      │
     │ + Cookie: laravel-session         │ check shop format                    │
     │                                   │ check hmac (uses API secret)         │
     │                                   │ [DB] read + delete state             │
     │                                   │ compare with state in URL            │
     │                                   │                                      │
 5   │                                   │── POST /admin/oauth/access_token ───▶│
     │                                   │   client_id, client_secret, code     │
     │                                   │◀─ access_token, refresh_token ───────│
     │                                   │                                      │
 6   │                                   │ [DB] shops ← tokens, encrypted       │
     │                                   │                                      │
 7   │◀─ 302 → your app inside admin ────│                                      │
     │                                   │                                      │
```

Look at step 5. It's the only step where the browser is not in the middle.
Laravel talks to Shopify directly. That's where the key changes hands, so the
browser never sees it.

---

## 5. Step by step: what goes in, what comes out

### Step 1: You open `/auth?shop=…`, and `install()` runs

**In** (request from Firefox):

```
GET https://although-tony-night-non.trycloudflare.com/auth?shop=tarun-dev-store-pnerqrpu.myshopify.com
Cookie: laravel-session=…; XSRF-TOKEN=…      ← left over from an earlier visit
```

**What `install()` does**
([ShopifyOAuthController.php:31](../app/Http/Controllers/Auth/ShopifyOAuthController.php)):

1. Checks that `shop` looks like `something.myshopify.com` (the pattern in
   `ShopDomain`). This stops `?shop=evil.com`. Section 7 explains why that matters.
2. Makes `state`: 32 random bytes, written as 64 hex characters.
3. Saves `{state, shop}` in the session, under the key `shopify_oauth`.
4. Sends the browser to Shopify.

**Saved in MySQL.** This is the `sessions` row `45N0nq…`, as Telescope's Session tab showed it:

```json
{
    "_token": "QrE3cyXs…",
    "shopify_oauth": {
        "state": "6902b36eed7ea04c…",
        "shop": "tarun-dev-store-pnerqrpu.myshopify.com"
    },
    "_previous": {
        "url": "https://although-tony-night-non.trycloudflare.com/auth?shop=…",
        "route": "auth.install"
    },
    "_flash": { "old": [], "new": [] }
}
```

Only `shopify_oauth` is ours. The other three are Laravel's own housekeeping:

- `_token`: Laravel's general anti-forgery token (see the `XSRF-TOKEN` cookie in section 6)
- `_previous`: the last page, for `redirect()->back()`
- `_flash`: one-time messages

**Out** (response to Firefox):

```
HTTP 302
Location: https://tarun-dev-store-pnerqrpu.myshopify.com/admin/oauth/authorize
            ?client_id=61c0dd61…                   ← which app
            &scope=read_customers,read_products    ← what it wants (SHOPIFY_SCOPES)
            &redirect_uri=https://although-tony-night-non.trycloudflare.com/auth/callback
                                                   ← where to send the browser back
            &state=6902b36eed7ea04c…               ← the random value
Set-Cookie: laravel-session=…; Max-Age=7200; secure; httponly; samesite=lax
Set-Cookie: XSRF-TOKEN=…;      Max-Age=7200; secure; samesite=lax
```

> **Find this match in your screenshots.** The `state` in the Location header
> (Response tab) and the `state` in the Session tab are the same string:
> `6902b36e…`. One half leaves with the browser, and the other stays in MySQL.
> Step 4 checks that they still match.

`redirect_uri` is built from `SHOPIFY_APP_URL` in `.env`, not from the incoming
request. Shopify rejects it unless it matches the Dev Dashboard's allowed list
character for character.

---

### Step 2: Shopify shows the consent screen

The browser makes **two** hops to get here, not one. The first is too quick to
notice in the address bar:

```
1. https://although-tony-night-non.trycloudflare.com/auth?shop=tarun-dev-store-pnerqrpu.myshopify.com
       │
       │  302 from Laravel (install)
       ▼
2. https://tarun-dev-store-pnerqrpu.myshopify.com/admin/oauth/authorize
       ?client_id=61c0dd61…
       &scope=read_customers,read_products
       &redirect_uri=https://although-tony-night-non.trycloudflare.com/auth/callback
       &state=6902b36e…                     ← state is in the URL here
       │
       │  302 from Shopify
       ▼
3. https://admin.shopify.com/store/tarun-dev-store-pnerqrpu/app/grant
       ?access_change_uuid=d41e2b43-…       ← state is gone from the URL
       &client_id=61c0dd61…
```

At URL 2, Shopify checks that the `client_id` is a real app and that the
`redirect_uri` is on its allowed list. URL 3 is Shopify's consent page (your
first screenshot). It turns `read_customers,read_products` into plain words
like "View customer data" and "View store data".

To see all three hops yourself: Firefox DevTools → **Network** → tick **Persist
Logs** in the gear menu → open the `/auth` URL again.

**Where did `state` go?** It isn't lost. Shopify holds it while the consent
screen is showing, and puts it back in the callback URL in step 3. The full
journey of `state`:

1. `install()` makes it and keeps one copy in MySQL (`shopify_oauth.state`).
2. The other copy leaves in URL 2.
3. Shopify holds that copy while the consent screen is open.
4. You click Install. Shopify puts it back in the callback URL, unchanged.
5. `callback()` deletes the MySQL copy as it reads it, then compares the two.

**What is `access_change_uuid`?** It belongs to Shopify, not to OAuth or to
your app. Your code never sees, sends or checks it, and it isn't one of the
parameters in Shopify's OAuth documentation. What follows is an inference from
its name and from what vanished between URL 2 and URL 3. It is not confirmed.

In URL 2 the browser carried everything: `scope`, `redirect_uri` and your
`state`. In URL 3 all of that is gone, and a single ID has replaced it. So
Shopify most likely saved your whole request on its side and gave the browser
an ID to find it again. When you click Install, Shopify looks the request up,
takes your `redirect_uri` and `state` back out, and builds the callback URL
from them.

**Two kinds of ticket:**

|               | `state`                            | `access_change_uuid`                             |
| ------------- | ---------------------------------- | ------------------------------------------------ |
| Made by       | Your app                           | Shopify                                          |
| Looks like    | 64 hex characters                  | A UUID                                           |
| Job           | **A check**: two copies must match | **A lookup**: points to data stored somewhere else |
| Analogy       | Torn ticket                        | Cloakroom ticket                                 |
| Who uses it   | Your `callback()`                  | Shopify only                                     |
| In your code? | Yes                                | No                                               |

The cloakroom ticket is the same pattern as your `laravel-session` cookie
(section 6). The browser holds only an ID, and the real data stays on the
server. Both servers are doing the same thing:

- Your app keeps `state` in MySQL and gives the browser a session ID.
- Shopify keeps your request (with your `state` inside it) and gives the
  browser `access_change_uuid`.

**Laravel is not involved here at all.** That's why nothing new appears in
Telescope while the consent screen is open.

_Why does it say "Update data access"?_ That wording suggests Shopify still
thinks the app is installed on this store from an earlier install. But your
`shops` table was empty when I checked (29 Sep, around 20:45). So Shopify's
records and your database disagree, most likely because the local database was
reset after the last install. Clicking Install will create the row again.

---

### Step 3: You click Install

Shopify does three things:

1. Records that the owner said yes.
2. Makes a one-use `code`.
3. Signs all the other parameters with **your API secret**. That signature is the `hmac`.

Then it sends the browser back to you:

```
HTTP 302
Location: https://although-tony-night-non.trycloudflare.com/auth/callback
            ?code=…                    ← the one-use cheque
            &hmac=…                    ← Shopify's signature over everything else
            &host=YWRtaW4uc2hvcGlmeS5jb20v…
                                       ← base64 of admin.shopify.com/store/tarun-dev-store-pnerqrpu
            &shop=tarun-dev-store-pnerqrpu.myshopify.com
            &state=6902b36eed7ea04c…   ← your value, handed back untouched
            &timestamp=…               ← when Shopify signed it
```

Shopify doesn't know what `state` means. It hands it back exactly as you sent
it. It's a note from your app to itself.

**Will the browser send the session cookie?** The browser is being sent from
`admin.shopify.com` to your tunnel, which is a different site. Browsers are
careful about sending cookies in that situation. Your cookie says
`SameSite=Lax`, which means: _send me when the user navigates here with a normal
GET, even if another site started it._ This is exactly that case, so the
cookie goes along.

If the cookie said `SameSite=Strict`, the browser would drop it. Laravel would
find no state, and every install would fail with "Invalid or expired OAuth
state". Lax is Laravel's default (`config/session.php`), and it's the setting
that makes this flow work.

---

### Step 4: `callback()` runs its checks

**In:**

- The six query parameters above.
- `Cookie: laravel-session=…`. Laravel decrypts it with `APP_KEY`, gets session
  ID `45N0nq…`, loads that row, and finds `shopify_oauth.state = 6902b36e…`.

**The checks, in order**
([ShopifyOAuthController.php:51](../app/Http/Controllers/Auth/ShopifyOAuthController.php)):

| #   | Check        | Question it answers                                     | If it fails                        |
| --- | ------------ | ------------------------------------------------------- | ---------------------------------- |
| 1   | Shop format  | Is this a real `*.myshopify.com` domain?                | 400                                |
| 2   | HMAC         | Did Shopify send this, with nothing changed?            | 403 Invalid HMAC                   |
| 3   | State        | Did _this browser_ start this install, for _this_ shop? | 403 Invalid or expired OAuth state |
| 4   | Code present | Is there a cheque to cash?                              | 400                                |

**How the HMAC check works**
([OAuthHmacVerifier.php](../app/Services/Shopify/OAuthHmacVerifier.php)):

1. Take every parameter except `hmac`.
2. Sort them by name: `code`, `host`, `shop`, `state`, `timestamp`.
3. Join them like a query string: `code=…&host=…&shop=…&state=…&timestamp=…`
4. Run HMAC-SHA256 over that text, using your API secret as the key.
5. Compare the result with the `hmac` Shopify sent, using `hash_equals`. That
   comparison takes the same time whether the first character is wrong or the
   last, so an attacker can't learn the signature by timing it.

Only Shopify and Laravel know the secret, so only they can make a matching
signature. Change one character of any parameter and the signatures no longer
match.

One small but real detail: Laravel normally trims spaces from input and turns
empty strings into `null`. [bootstrap/app.php](../bootstrap/app.php) switches
both off for `/auth/callback`, because any changed value would break the
signature.

**How the state check works:** `session()->pull('shopify_oauth')` reads the
saved value **and deletes it** in one step. Then the code compares:

- the saved state with the URL's state (again using `hash_equals`)
- the saved shop with the URL's shop

Because the value is deleted as it's read, the same callback URL can never
pass twice.

---

### Step 5: Laravel swaps the code for the token, server to server

**Out** (Laravel → Shopify, `install()` in
[OAuthService.php](../app/Services/Shopify/OAuthService.php)):

```
POST https://tarun-dev-store-pnerqrpu.myshopify.com/admin/oauth/access_token
Content-Type: application/x-www-form-urlencoded

client_id=61c0dd61…&client_secret=<API secret>&code=…&expiring=1
```

**In** (Shopify → Laravel):

```json
{
  "access_token": "…",
  "expires_in": …,
  "refresh_token": "…",
  "refresh_token_expires_in": …,
  "scope": "read_customers,read_products"
}
```

**Why is it safe for the `code` to travel in the browser's URL?** Because the
code alone is worthless. Cashing it also needs the `client_secret`, and only
Laravel has that. Remember the cheque analogy: anyone can see it, but only you
can cash it. And once it's cashed, it's dead.

`expiring=1` asks for a token that expires, plus a refresh token to get new
ones. Later, `freshAccessToken()` handles the refresh before each Admin API call.
[how-api-auth-works.md](how-api-auth-works.md), sections 6 and 7, walks through
the refresh and what happens when it fails.

Laravel then checks `scope`. If Shopify granted less than `SHOPIFY_SCOPES`
asked for, the install fails, rather than saving a token that can't do its job.

In Telescope this appears under **HTTP Client**. `client_secret`,
`access_token` and `refresh_token` show as `********`, because
[TelescopeServiceProvider.php](../app/Providers/TelescopeServiceProvider.php)
hides them.

---

### Step 6: Save the shop

`Shop::updateOrCreate()` writes one row in `shops`, found by `shop_domain`:

| Column                     | Value                                                  |
| -------------------------- | ------------------------------------------------------ |
| `shop_domain`              | `tarun-dev-store-pnerqrpu.myshopify.com`               |
| `access_token`             | encrypted with `APP_KEY`                               |
| `access_token_expires_at`  | now + `expires_in`                                     |
| `refresh_token`            | encrypted with `APP_KEY`                               |
| `refresh_token_expires_at` | now + `refresh_token_expires_in`                       |
| `scopes`                   | `read_customers,read_products`                         |
| `installed_at`             | now                                                    |
| `uninstalled_at`           | `null` (a reinstall revives the old row and its tiers) |

The two tokens are encrypted by the model's `encrypted` cast
([Shop.php](../app/Models/Shop.php)). Someone with only a copy of the database
can't read them.

---

### Step 7: Into the admin

```
HTTP 302
Location: https://tarun-dev-store-pnerqrpu.myshopify.com/admin/apps/61c0dd61…
```

Shopify opens its admin and loads your app inside an iframe. From here,
[how-api-auth-works.md](how-api-auth-works.md) takes over.

---

## 6. Your screenshots, decoded

### The two rows in `sessions`

| id        | last_activity                   | What it is                                                      |
| --------- | ------------------------------- | --------------------------------------------------------------- |
| `45N0nq…` | 20:40:05                        | **The install.** Created by `GET /auth`. Holds `shopify_oauth`. |
| `ZJjwuP…` | 20:40:25, and 20:43:59 later on | **Not the install. This is Telescope's own session.**           |

Why is there a second session? You opened Telescope at `http://localhost:8000`.
That's a different address from the tunnel, and browsers keep separate cookies
for each address. So `localhost` got its own cookie, and Laravel gave it its
own session. (Telescope's pages use the same `web` middleware as `/auth`.)

The evidence:

- That row has no `shopify_oauth`.
- Its `last_activity` kept moving while you browsed Telescope.
- Telescope doesn't record requests to itself, which is why no Telescope entry matches it.

The `payload` column is readable because `SESSION_ENCRYPT=false` and sessions
are stored as JSON. It's only base64, which is an encoding, not encryption:
anyone can reverse it.

```bash
echo 'eyJfdG9rZW4iOiJSRHFs…' | base64 -d
```

Row 2 decodes to `{"_token":"RDqlhUmr…","_flash":{"old":[],"new":[]}}`. Row 1's
value in your paste was cut short by the database tool, so it decodes only
partway. Telescope's Session tab shows the whole thing.

### The three queries on the `/auth` request

In the order they ran:

1. `select * from sessions where id = '45N0nq…'`: Laravel looks up this
   browser's session. There isn't one yet.
2. The same `select` again. Before saving, the database session driver checks
   once more whether to insert or update. That's Laravel's own code, not yours,
   and it's the "1 duplicated" Telescope points out.
3. `insert into sessions …`: the new row, with the state inside.

### The two cookies

**`laravel-session`.** First undo the URL encoding (`%3D` is `=`), then
base64-decode it. You get Laravel's encryption envelope:

```json
{ "iv": "PJM8WO7p…", "value": "pYHd/8o6…", "mac": "37ddd914…", "tag": "" }
```

- `iv`: a random starting value, so the same session ID encrypts differently every time.
- `value`: the session ID, encrypted with `APP_KEY`.
- `mac`: a tamper seal. Change one character and Laravel rejects the cookie.

Think of this cookie as a cloakroom ticket. The browser holds the ticket, and
the coat (the state) stays in MySQL.

**`XSRF-TOKEN`.** This is Laravel's general protection against forged form
submissions: the session's `_token`, encrypted. Laravel only checks it on
`POST`, `PUT`, `PATCH` and `DELETE`. The OAuth callback is a `GET`, so this
protection never runs there. **That's exactly why OAuth brings its own `state`.**

**The cookie flags in the response:**

| Flag           | Meaning                                                                            | Why it's set here                                                                                                              |
| -------------- | ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `secure`       | Only sent over HTTPS                                                               | The request came in over HTTPS through the tunnel. The tunnel says so in `X-Forwarded-Proto`, which `bootstrap/app.php` trusts |
| `httponly`     | JavaScript can't read it                                                           | Session cookie only. `XSRF-TOKEN` leaves it off on purpose, so JavaScript can send it back                                     |
| `samesite=lax` | Sent on normal navigations from other sites, but not on hidden cross-site requests | Makes step 3 work                                                                                                              |
| `Max-Age=7200` | Lives 2 hours                                                                      | `SESSION_LIFETIME=120` minutes. You have 2 hours between `/auth` and clicking Install                                          |

### Other fields in the Telescope request

| Field            | Value                                       | Meaning                                                                                                                                                                                                                                                                                                                            |
| ---------------- | ------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| IP Address       | `192.168.65.1`                              | Not you. It's Docker Desktop's internal gateway. The request goes Firefox → Cloudflare → cloudflared on your Mac → `localhost:8000` → Docker → nginx → Laravel, so Laravel only sees the last hop. Your real IP is in `cf-connecting-ip`. Laravel ignores that header because `bootstrap/app.php` trusts only `X-Forwarded-Proto`. |
| Hostname         | `cce8774676ab`                              | The Docker container's ID                                                                                                                                                                                                                                                                                                          |
| `host` header    | `although-tony-night-non.trycloudflare.com` | The tunnel address you typed                                                                                                                                                                                                                                                                                                       |
| `cf-*` headers   |                                             | Added by Cloudflare on the way through                                                                                                                                                                                                                                                                                             |
| `sec-fetch-site` | `none`                                      | You typed the URL yourself, so no other site sent you. On the callback it should say `cross-site`, because Shopify sent you.                                                                                                                                                                                                       |

---

## 7. What would go wrong without each check

These are honest answers. Some checks stop serious attacks. Others are a backup
for a check that already exists.

| If we skipped…                            | What an attacker could do                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | How bad, here                            |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------- |
| **Shop format check in `install`**        | Send a merchant a link to `/auth?shop=evil.com`. Your app would redirect them to `https://evil.com/admin/oauth/authorize`, a fake Shopify login page reached through your trusted link. **This is the phishing case.** It's called an _open redirect_.                                                                                                                                                                                                                                                                                                       | Serious                                  |
| **Shop format check in `callback`**       | Make Laravel post your `client_secret` to a server they own, in step 5. The HMAC check also blocks this, so the format check is the first wall and HMAC is the second.                                                                                                                                                                                                                                                                                                                                                                                       | Serious, but covered twice               |
| **HMAC**                                  | Hand-craft callback URLs with any parameters they like. The state check and Shopify's own code check would still catch most of these, but HMAC rejects them before any work is done. Shopify requires it.                                                                                                                                                                                                                                                                                                                                                    | Backup, and required                     |
| **State**                                 | Start an install on _their own_ store, stop before the callback, and send that genuine, Shopify-signed link to you. HMAC passes, because Shopify really did sign it. Only the state check notices that your browser never started this flow. This is **CSRF** (cross-site request forgery: another site tricks your browser into sending a request you didn't mean to send). In this app the damage would be small, because there are no user accounts to link a store to. In apps with accounts, it's how an attacker attaches their store to your account. | Small here, serious in general. Required |
| **Deleting the state after use** (`pull`) | Replay the same callback URL. Shopify would refuse the used code in step 5 anyway. Deleting the state just makes the replay fail earlier and more cheaply.                                                                                                                                                                                                                                                                                                                                                                                                   | Backup                                   |
| **Server-side code exchange**             | If the browser did the exchange, the access token would sit in the browser, where DevTools, a browser extension or an XSS bug could take it.                                                                                                                                                                                                                                                                                                                                                                                                                 | Serious. It's rule 1 in CLAUDE.md        |

**The key idea:** HMAC and state answer different questions.

- HMAC asks: _did Shopify send this?_
- State asks: _did this browser ask for it?_

A genuine Shopify link can still reach the wrong person. That's why you need both.

---

## 8. Try it now and watch it happen

Your consent tab is still open. The state stays valid until about **22:40**
(20:40 plus 2 hours), as long as the tunnel is still running at the same address.

Before you click, run this query. It should return the row holding `shopify_oauth`:

```sql
SELECT id, FROM_BASE64(payload) FROM sessions
WHERE FROM_BASE64(payload) LIKE '%shopify_oauth%';
```

1. Click **Install**.
2. Telescope → **Requests** → the `/auth/callback` entry → **Payload**. Expect
   `code`, `hmac`, `host`, `shop`, `state`, `timestamp`. The `state` should be `6902b36e…`.
3. Same entry → **Session** tab. `shopify_oauth` should be gone, because `pull()`
   deleted it. Run the SQL query again to confirm: it should return no rows.
4. Same entry → **Queries**. Roughly: read the session, look up `shops`, insert
   into `shops`, update `sessions`.
5. Telescope → **HTTP Client**. There should be a `POST …/admin/oauth/access_token`
   with the secrets shown as `********`.
6. Firefox should land inside the Shopify admin, with your app loaded.
7. **Replay test:** copy the `/auth/callback?…` URL from Telescope and open it
   again. Expect **403 Invalid or expired OAuth state**. That's the one-use
   protection working.

---

## 9. After install: how each API call is authenticated

This has its own document now: [how-api-auth-works.md](how-api-auth-works.md).
It covers how App Bridge attaches the ID token, why moving between screens
authenticates nothing, how the one-hour access token is renewed, what the
merchant sees when it can't be, and why jwt.io can't read the `access_token`
column.

---

## 10. Words used in this document

- **OAuth**: the standard way for an app to get permission to act on someone's account without knowing their password.
- **Redirect (302)**: the server answers "go here instead", and the browser follows automatically.
- **state / nonce**: a random value used once, to tie the end of a process to its start.
- **HMAC**: a signature made from a message plus a secret key. Only someone who has the key can make a matching one.
- **CSRF**: another site tricks your browser into sending a request you didn't mean to send.
- **Cookie**: a small value the browser stores for each site and sends back on every request to that site.
- **Session**: data Laravel keeps on the server for one browser, found using the ID in the cookie.
- **base64**: a way to write any data as plain letters and digits. It's an encoding, not encryption, so anyone can reverse it.
- **JWT / ID token**: a small signed package of JSON. Anyone can read it, but nobody can change it without breaking the signature.
- **Access token**: the key that lets Laravel call the Shopify Admin API for one store. A random string, not a JWT. Here it lives one hour.
- **Refresh token**: a second key, good for 90 days, whose only use is getting a new access token.
- **Scope**: one permission, like `read_customers`.

---

## 11. Saying it in an interview (about 40 seconds)

> Install is standard OAuth. The install route makes a random state, keeps it
> in the server session, and redirects to Shopify's consent screen. When the
> merchant approves, Shopify sends the browser back with a one-time code, the
> state, and an HMAC. The callback checks the HMAC, to prove Shopify sent it
> unchanged, and the state, to prove this browser started the flow. That's
> the CSRF defence. The state is deleted as it's read, so the URL works once.
> Then Laravel swaps the code for an access token, server to server, using the
> client secret, and stores it encrypted. The browser never sees the token.
> After install, each API call is authenticated by the ID token App Bridge
> attaches, not by cookies.
