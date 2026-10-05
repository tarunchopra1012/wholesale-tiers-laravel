# System design: how Laravel and the checkout Function work together

_Written 5 Oct 2026 from the code, after the first discounted checkout on
`tarun-dev-store-pnerqrpu`: a `wholesale-gold` customer paid $583.96 for a
$729.95 snowboard, 20% off._

This covers the one part of the app that does not run on our server: the
discount at checkout. For how the admin pages are authenticated, see
[how-install-auth-works.md](how-install-auth-works.md) and
[how-api-auth-works.md](how-api-auth-works.md).

---

## 1. The short version

The app has two programs, and they never call each other.

|                        | **Laravel app**                                        | **Checkout Function**                        |
| ---------------------- | ------------------------------------------------------ | -------------------------------------------- |
| Runs on                | Our server (Docker locally, a container in production) | Shopify's servers, as WebAssembly            |
| Runs when              | The merchant uses the app in the admin                 | A customer's cart or checkout is priced      |
| Written in             | PHP                                                    | JavaScript, compiled by Shopify CLI          |
| Source                 | `app/`                                                 | `extensions/wholesale-tier-discount/`        |
| Released by            | Building and deploying the container image             | `shopify app deploy`                         |
| Can call Shopify's API | Yes, with the shop's access token                      | No. It has no token and no network           |
| Can read our database  | Yes                                                    | No                                           |
| Knows the customer     | Only by asking the Admin API                           | Yes: Shopify hands it the cart and the buyer |

Laravel knows the tiers but is not there at checkout. The Function is there at
checkout but knows nothing. So Laravel leaves a note where the Function can
read it: **a JSON metafield on a discount in the merchant's store.** That note
is the whole interface between the two.

Think of a shop with a price list taped beside the till. The manager (Laravel)
rewrites the list whenever prices change. The cashier (the Function) reads it
for every customer and never phones the manager.

---

## 2. The pieces

```
┌──────────────────────────── our server ─────────────────────────────┐
│                                                                      │
│  Settings page (React)                                               │
│      │  POST / PUT / DELETE /api/tiers                               │
│      ▼                                                               │
│  TierSettingController ──► MySQL: tier_settings   (source of truth)  │
│      │                                                               │
│      ▼                                                               │
│  TierDiscountSync ◄── TierDiscountConfig (tiers → JSON, pure)        │
│      │                                                               │
└──────┼───────────────────────────────────────────────────────────────┘
       │  Admin GraphQL API, with the shop's access token
       ▼
┌──────────────────────────── Shopify ────────────────────────────────┐
│                                                                      │
│  Automatic discount "Wholesale tiers"                                │
│    • points at the Function by its handle, wholesale-tier-discount   │
│    • metafield  $app:wholesale-tiers / function-configuration        │
│                                                                      │
│                  read on every cart and checkout                     │
│                              ▼                                       │
│  Function  wholesale-tier-discount  (WebAssembly)                    │
│    input:  cart lines, the customer's tier tags, the metafield       │
│    output: which lines get which discount                            │
│                              ▼                                       │
│  Checkout shows  WHOLESALE-GOLD (-$145.99)                           │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
```

Three things have to exist in the store before a discount appears:

1. **A released app version that contains the Function.** `shopify app deploy`
   does this. It only makes the Function available.
2. **A discount that points at the Function.** A Function on its own never
   runs. Laravel creates the discount on the first sync.
3. **The metafield on that discount.** Laravel writes it on every sync.

---

## 3. The contract: one metafield

|                |                                                                                                                        |
| -------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Owner          | The "Wholesale tiers" automatic discount                                                                               |
| Namespace      | `$app:wholesale-tiers`                                                                                                 |
| Key            | `function-configuration`                                                                                               |
| Type           | `json`                                                                                                                 |
| Written by     | [TierDiscountSync.php](../app/Services/Shopify/TierDiscountSync.php)                                                   |
| Shape built by | [TierDiscountConfig.php](../app/Support/TierDiscountConfig.php)                                                        |
| Read by        | [cart_lines_discounts_generate_run.js](../extensions/wholesale-tier-discount/src/cart_lines_discounts_generate_run.js) |

```json
{
    "tags": ["wholesale-gold", "wholesale-silver"],
    "tiers": [
        { "tag": "wholesale-gold", "type": "percentage", "value": "20.00" },
        { "tag": "wholesale-silver", "type": "percentage", "value": "10.00" }
    ]
}
```

- **`tiers`** is what the Function prices with. `type` is `percentage` or
  `fixed`. `value` is a decimal string, straight from the database's
  `decimal(10,2)` column, so it never passes through a float.
- **`tags`** repeats the tags on purpose. The Function cannot read a
  customer's tag list; it can only ask "does this customer have these tags?",
  and it has to name them. Shopify fills the input query's `$tags` variable
  from this key. That wiring is the `[extensions.input.variables]` block in
  [shopify.extension.toml](../extensions/wholesale-tier-discount/shopify.extension.toml).
- **Laravel always writes the whole list.** Never one changed tier. The
  metafield is a copy of `tier_settings`, and a full overwrite cannot drift.
- **`$app:` makes the namespace private to this app.** Another app cannot
  read or overwrite it.

A change to this shape needs the same change on both sides, in one release:
`TierDiscountConfig` and its test, and the Function and its fixtures.

---

## 4. Flow 1: the merchant saves tiers

```
Merchant presses Save on the Settings page
  │  PUT /api/tiers, Authorization: Bearer <ID token>
  ▼
VerifyShopifySessionToken        which shop is this?
  ▼
TierSettingController::update
  │  1. validate (Form Request)
  │  2. save to tier_settings, in one transaction
  │  3. TierDiscountSync::sync($shop)      ← after the commit, not inside it
  ▼
TierDiscountSync
  │  build the JSON from every tier of the shop
  │
  ├─ shop has no tier_discount_id
  │     discountAutomaticAppCreate, with the metafield already on it
  │     save the new ID on the shops row
  │
  ├─ shop has a tier_discount_id, and Shopify still has that discount
  │     metafieldsSet on that discount
  │
  └─ shop has a tier_discount_id, but the merchant deleted the discount
        discountAutomaticAppCreate again, save the new ID
  ▼
shops.tier_discount_synced_at = now
```

The same sync runs after adding a tier (`POST`) and deleting one (`DELETE`).

Design choices here:

- **Saving is publishing.** There is no separate "push to checkout" button for
  a merchant to forget.
- **The sync runs after the database commit.** A slow Shopify must not hold
  database locks, and a failed sync must not undo the merchant's save.
- **A failed sync answers 502** with a sentence saying the tiers are saved but
  checkout still has the previous ones. Pressing Save again retries. Dead
  tokens still answer 403 with the Reconnect button, as on every other page.
- **The discount is created with its metafield in the same mutation**, so the
  Function never runs against a discount with no configuration.
- **The sync checks that the discount still exists** before writing. The
  merchant owns their Discounts list and can delete ours.
- **Shopify reports a refused mutation in `userErrors`** with HTTP 200, so
  `TierDiscountSync` checks that list itself; the GraphQL client cannot see it.

Two columns on `shops` hold the state: `tier_discount_id` (the discount's
Shopify ID) and `tier_discount_synced_at`.

---

## 5. Flow 2: a customer reaches checkout

Laravel is not involved in any step of this.

```
Customer adds a product to the cart
  ▼
Shopify finds the active automatic discounts; "Wholesale tiers" is one
  ▼
Shopify reads the discount's metafield and takes "tags" for $tags
  ▼
Shopify runs the Function's input query and builds the input:
    • each cart line: id, price of one unit
    • for each tag in $tags: does the logged-in customer have it?
    • the discount's classes and its metafield
  ▼
Function (pure: input in, output out)
    1. tiers        = metafield.tiers
    2. customerTiers = tiers whose tag the customer has
    3. none, or the discount is not a product discount → no operations
    4. for each line, pick the tier that takes the most off one unit
    5. return one discount candidate per winning tier, listing its lines
  ▼
Shopify applies the discounts and shows them in the cart and at checkout
```

Rules the Function follows:

- **A guest gets nothing.** With no logged-in customer there are no tags.
- **Tags match without regard to case**, as they do everywhere in Shopify.
- **A customer in two tiers gets the larger discount, line by line.** A
  percentage and a fixed amount can swap places between a cheap product and a
  dear one: 10% beats 5.00 off on a 100.00 product and loses to it on a 20.00
  one. The winner is chosen in the Function's own code so the fixtures can
  test it.
- **A fixed amount comes off each unit**, which is what the Settings page says
  and what the Price preview shows.
- **The label at checkout is the tier's tag.** A tier has no other name yet.

The Function is tested without Shopify: the fixtures in
`extensions/wholesale-tier-discount/tests/fixtures/` are input and expected
output pairs, run against the built WebAssembly.

---

## 6. Two calculators, one rule

The tier rule exists in two places because it runs in two places:

|          | Price preview                                 | Checkout                                                                       |
| -------- | --------------------------------------------- | ------------------------------------------------------------------------------ |
| Code     | `TierCalculator` (PHP)                        | The Function hands Shopify a percentage or amount; Shopify does the arithmetic |
| Purpose  | Show the merchant what a tier will pay        | Charge it                                                                      |
| Rounding | Ours: final price rounded half-up to the cent | Shopify's                                                                      |

They can differ by a cent at an exact half cent. The preview is a preview;
checkout is the price.

---

## 7. What can go wrong, and what happens

| Situation                                                                     | Result                                                                                   |
| ----------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| Tiers exist but nobody has pressed Save since this feature shipped            | No discount in the store, so no discount at checkout. One Save fixes it                  |
| Shopify is down or refuses during a save                                      | Tiers saved, 502 shown, checkout keeps the previous tiers until the next successful save |
| The merchant deletes "Wholesale tiers" in Discounts                           | No discount at checkout until the next save, which creates it again                      |
| The merchant deactivates the discount                                         | No discount at checkout. The sync leaves it deactivated: that is the merchant's choice   |
| A new Function is deployed with a different metafield shape, but nobody saves | The Function reads the old shape. Press Save after such a deploy                         |
| The Function throws                                                           | Shopify applies no discount for that run. Checkout still works                           |
| Two saves at the same moment on a shop with no discount yet                   | Both could create a discount. Not guarded; a POC limit                                   |

---

## 8. Why it is built this way

- **Why a Function at all?** A price at checkout has to be computed on
  Shopify's servers. No app server sits in that path, so nothing in Laravel
  could ever change what a customer pays.
- **Why a metafield, not an API call from the Function?** The Function has no
  network access and no access token. Shopify hands it data; it cannot fetch
  any.
- **Why an automatic discount, not a discount code?** Wholesale customers
  should not type anything. An automatic discount runs for every cart, and the
  Function decides whether this customer gets it.
- **Why one discount for all tiers, not one per tier?** One thing to create,
  find again and keep in sync, and one place where "which tier wins" is
  decided.
- **Why does Laravel own the tiers, not Shopify?** The Settings page,
  validation and Price preview need them in a real table. The metafield is a
  published copy.

For the commands to change, deploy and test the Function, see
`.claude/shopify-function-guide.md` (local only; `.claude` is not committed).
