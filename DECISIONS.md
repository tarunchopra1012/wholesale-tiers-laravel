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
