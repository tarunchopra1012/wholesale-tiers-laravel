# How the frontend flows: layers, the loading hook, and each page

_Written 8 Oct 2026 from the code on the `frontend-refactor-typescript`
branch, after the pages were split into components and moved to TypeScript._

[how-the-code-flows.md](how-the-code-flows.md) lists every function, file by
file. This document is the picture that goes with its part two: how the
frontend's pieces call each other, and how each page moves from state to
screen and back.

---

## 1. The short version

- **Pages hold state and layout only.** Each page keeps the few things that
  are truly its own and hands everything else down.
- **Reads go through one hook, `useApi(path)`.** A page never says "fetch".
  It passes a path; when the path changes, the hook loads it.
- **Components only draw.** They receive props and render Polaris elements.
  They never fetch.
- **Every page is a loop.** State decides the path, the path decides what is
  loaded, what is loaded decides what is drawn, and the merchant's actions
  change the state again.

---

## 2. The layers

Each layer only calls the one below it.

```
                      main.tsx and App.tsx
                   mount React, pick the page
                              │
          ┌───────────────────┼───────────────────┐
          ▼                   ▼                   ▼
    Customers.tsx        Settings.tsx        Preview.tsx
    filter and           tier form state,    picked product
    cursor state         writes              state
          └───────────────────┬───────────────────┘
                              │  every page uses both
              ┌───────────────┴───────────────┐
              ▼                               ▼
      hooks/useApi.ts                    components/
      data, error, loading, reload       six components that draw
              │                               │
              ▼                               ▼
      lib/api.ts                         lib/format.ts
      fetch, ApiError, reconnectAction   tierLabel, money, dateTime
              │
              ▼
      App Bridge's fetch                 lib/types.ts
      adds the ID token                  the API's shapes, imported everywhere
              │
              ▼
      Laravel /api routes
      JSON in, JSON out
```

- **The left column is the data path.** A page calls `useApi(path)`; the hook
  calls `api()`; App Bridge adds the token; Laravel answers. The answer comes
  back up the same column as `{ data, error, loading }`, and the page redraws.
- **The right column is the drawing path.** A page passes its data to a
  component as props; the component formats values with `lib/format.ts` and
  renders Polaris elements.
- **`lib/types.ts` sits to the side** because every file imports from it. It
  has no behaviour, only the shapes of the API's responses.

Two things the picture simplifies:

- **Settings also calls `api()` directly.** Its three writes (`save()`,
  `add()`, `remove()`) and its first tier load skip the hook, because the
  hook is only for reads whose answer is shown as it is.
- **Pages use `lib/format.ts` too**, not only components: Customers uses
  `tierLabel()` for the filter's options.

Before the refactor the middle two rows did not exist. Each page file held
its own copy of the fetching logic and its own sub-components, and called
`api()` straight from effects.

---

## 3. The loading hook: `useApi(path)`

[hooks/useApi.ts](../resources/js/hooks/useApi.ts). The page calls the hook on
every draw, but a request only goes out when one of two triggers fires.

```
   Page draws, calls useApi(path)
              │
              ▼
   Effect checks its triggers:          no
   new path, or reload() called?  ────────────▶  current state returned,
              │ yes                              no request made
              ▼
   State updated first:
   loading true; a new path clears the old answer
              │
              ▼
   api(path) sent  →  lib/api.ts  →  Laravel
              │
              ▼
   Answer arrives:                      no
   is this run still the newest?  ────────────▶  answer dropped,
              │ yes                              a newer run replaced it
              ▼
   State set: data or error; loading false
              │
              ▼
   Page redraws with the new state
```

- **The two triggers are the effect's dependencies, `[path, reloads]`.** A
  different path string, or a call to `reload()` (which bumps the `reloads`
  counter), runs the effect again. A redraw for any other reason returns the
  stored state and does nothing.
- **"State updated first" differs by trigger.**
    - _New path:_ `data` and `error` are cleared and `loading` becomes true. A
      new path is a new question, so the old answer must not stay on screen.
    - _`reload()`:_ only `loading` becomes true. The old answer and any old
      error stay visible until the new answer arrives.
    - The hook tells the two apart by remembering the last path it loaded in a
      ref, `loadedPath`.
- **"Is this run still the newest?" is the `ignore` flag.** Each run of the
  effect has its own `ignore` variable. When the effect runs again, React
  first calls the previous run's cleanup, which sets that run's `ignore` to
  true. A slow answer from an old run then finds `ignore` true and is dropped.
- **"State set" has two outcomes.** On success, `data` is the response body
  and `error` is null. On failure, `error` is the thrown `Error` and `data`
  is left as it was: already null after a path change, the old answer after a
  reload.
- **A null path means "nothing to load yet".** The effect returns at once and
  `loading` starts as false.
- **The first draw counts as a trigger.** The effect always runs once when
  the page appears, and `loading` starts as true so the skeleton shows
  straight away.

How each caller uses it:

| Caller                 | Path                            | What starts a new request               |
| ---------------------- | ------------------------------- | --------------------------------------- |
| Customers, tiers       | `/tiers`                        | First draw only                         |
| Customers, list        | `/customers?tier=…&after=…`     | The filter or the page changes the path |
| Settings, status       | `/checkout-status`              | `reload()` after each successful write  |
| Preview, first product | `/products?limit=1`             | First draw only                         |
| Preview, prices        | `/preview?product_id=…` or null | The product changes the path            |

---

## 4. Customers page

[pages/Customers.tsx](../resources/js/pages/Customers.tsx). A loop: the three
actions at the bottom change the state at the top.

```
   useApi('/tiers')                      Page state:  tier, cursors  ◀─────────┐
   once: filter options, badges                    │                           │
          │                                        ▼                           │
          │                              Path built on each draw               │
          │                              /customers?tier=…&after=…             │
          │                                        │                           │
          │                                        ▼                           │
          │                              useApi(path)                          │
          │                              loads when the path changes           │
          │                                        │                           │
          │                                        ▼                           │
          └────────────────────────────▶ Page draws:                           │
                                         skeleton, banner or table             │
                                                   │                           │
                    ┌──────────────────────────────┼──────────────────┐        │
                    ▼                              ▼                  ▼        │
              changeTier()                       Next              Previous    │
              sets tier,                         appends           drops the   │
              resets cursors                     end_cursor        last cursor │
                    └──────────────────────────────┴──────────────────┴────────┘
```

- **The page stores only two things:** `tier` (the selected tag, `''` for
  all) and `cursors` (the cursor each visited page started after, beginning
  as `[null]`). The customers themselves are not page state; they come from
  the hook.
- **The path is rebuilt on every draw** from `tier` and the last cursor. The
  page just passes a different path, and `useApi` loads because it changed.
- **The tiers load is separate and runs once.** If it fails, the customers
  are still listed, with a warning banner and no badges.

What "Page draws" shows:

| Hook state          | Result                                                                               |
| ------------------- | ------------------------------------------------------------------------------------ |
| `loading`           | `CustomerList` shows a skeleton                                                      |
| `error`             | A critical banner, with Reconnect when the error carries the URL; no table           |
| `data` with no rows | "No customers in this tier"                                                          |
| `data` with rows    | The table; `TierBadges` gives each customer one badge per matching tier, or "Retail" |

The three actions, with an example:

| Action                         | `cursors` before         | `cursors` after          | Path loaded                      |
| ------------------------------ | ------------------------ | ------------------------ | -------------------------------- |
| Next on page 1                 | `[null]`                 | `[null, "cur1"]`         | `/customers?after=cur1`          |
| Next on page 2                 | `[null, "cur1"]`         | `[null, "cur1", "cur2"]` | `/customers?after=cur2`          |
| Previous on page 3             | `[null, "cur1", "cur2"]` | `[null, "cur1"]`         | `/customers?after=cur1`          |
| `changeTier('wholesale-gold')` | anything                 | `[null]`                 | `/customers?tier=wholesale-gold` |

Previous works this way because Shopify only hands out a cursor for the next
page, so the page steps back through cursors it has already seen. The page
number in the footer is `cursors.length`. Changing the filter resets the
cursors because a cursor only means something within the search it came from.

---

## 5. Settings page

[pages/Settings.tsx](../resources/js/pages/Settings.tsx). The only page that
writes.

```
                         Settings page opens
                      two loads start together
                 ┌──────────────┴──────────────┐
                 ▼                             ▼
          Load effect                   useApi('/checkout-status')  ◀────────┐
          api('/tiers') into            feeds the status card                │
          the tiers state                                                    │
                 │                                                           │
                 ▼                                                           │
          Tier cards drawn                                                   │
          from the tiers state                                               │
                 │                                                           │
     ┌───────────┼──────────────┬──────────────────┐                         │
     ▼           ▼              ▼                  ▼                         │
  change()     save()         add()            remove()                      │
  local state  PUT all cards  POST the draft   DELETE one tier               │
  only           │              │                  │                         │
                 └──────────────┼──────────────────┘                         │
              ┌─────────────────┼─────────────────┐                          │
              ▼                 ▼                 ▼                          │
        Other error       422 from Laravel     Success                       │
        banner shows      messages under       toast, then                   │
        the message       the fields           checkout.reload() ────────────┘
```

When the page opens:

- The two loads are independent, so the tier cards appear even if Shopify is
  slow to answer the status check.
- The tier load is a hand-written effect because its result becomes editable
  state. The status check uses `useApi()` because its answer is only shown.

The four actions:

| Function   | Triggered by                                          | What it sends                                                                  |
| ---------- | ----------------------------------------------------- | ------------------------------------------------------------------------------ |
| `change()` | Typing in any field of a card                         | Nothing. It replaces that one field in the `tiers` state and the card redraws. |
| `save()`   | The Save button                                       | `PUT /api/tiers` with every card                                               |
| `add()`    | "Add tier" in the dialog (opened by `openAdd()`)      | `POST /api/tiers` with the `draft`                                             |
| `remove()` | "Delete tier" in the dialog (opened by `askDelete()`) | `DELETE /api/tiers/{id}`                                                       |

The three outcomes:

- **Success.** The page updates `tiers` from the response and shows a toast:
  Save replaces the list with the server's version, Add appends the new tier,
  Delete filters it out. Add and Delete also close their dialog. Then
  `checkout.reload()` runs.
- **422 from Laravel.** Add puts the messages in `draftErrors`, shown inside
  the dialog. Save puts them in `fieldErrors`, keyed by position such as
  `tiers.0.discount_value`, and each card's `TierFields` shows its own. A key
  that matches no visible field goes to the banner. Delete has no validation,
  so it never ends here.
- **Other error.** The message goes to a banner: on the page for Save, inside
  the dialog for Add and Delete. This includes the 502 that means "saved
  here, but not yet at checkout".

Only the success path reloads the status; a failed write leaves the status
card as it was.

`TierFields` is drawn in two places from the same component: once per card
(wired to `change()` and `fieldErrors`) and once in the Add dialog (wired to
`draft` and `draftErrors`).

---

## 6. Price preview page

[pages/Preview.tsx](../resources/js/pages/Preview.tsx). A loop, like the
Customers page: the picker at the bottom feeds back into the state at the top.

```
   useApi('/products?limit=1')           picked state  ◀───────────────────┐
   once: the first product               set by the product picker         │
              └──────────────┬──────────────────┘                          │
                             ▼                                             │
                   product worked out:                                     │
                   picked, else the first product                          │
                             │                                             │
                             ▼                                             │
                   Path built on each draw                                 │
                   /preview?product_id=…, or null                          │
                             │                                             │
                             ▼                                             │
                   useApi(path)                                            │
                   loads when the product changes                          │
                             │                                             │
                             ▼                                             │
                   Page draws:                                             │
                   product header, price table                             │
                             │                                             │
                             ▼                                             │
                   pickProduct()                                           │
                   opens Shopify's product picker ─────────────────────────┘
```

- **Two sources, one product.** `start` is the store's first product, loaded
  once so the page has something to show. `picked` is whatever the merchant
  chose, and starts as null. The product to price is `picked` if there is
  one, otherwise the first product. It is worked out on each draw and never
  stored.
- **The path can be null.** With no product (the first load has not answered
  yet, or the store is empty), the page passes `null` to `useApi`, which then
  loads nothing.
- **A new product is a new path.** `useApi` clears the old prices as soon as
  the path changes, so a failed request cannot leave the previous product's
  prices under the new name.
- **The prices come from Laravel.** The page sends only the product's ID;
  `TierCalculator` works out each tier's price on the server.

What "Page draws" shows:

| Situation             | Result                                                                                    |
| --------------------- | ----------------------------------------------------------------------------------------- |
| First load in flight  | The "Choose product" button is disabled; `PriceTable` shows a skeleton                    |
| First load failed     | A critical banner, with Reconnect when available. The picker still works.                 |
| Store has no products | `ProductHeader` shows "This store has no products yet."                                   |
| Prices in flight      | The product's title, and a skeleton for the table                                         |
| Prices failed         | A critical banner "Couldn't work out the prices"; no table                                |
| Prices loaded         | A "Retail" row at the base price, then one row per tier with its discount and final price |

What `pickProduct()` can do:

| Picker result                    | Effect                                                                         |
| -------------------------------- | ------------------------------------------------------------------------------ |
| A product is chosen              | `picked` is set to its `{ id, title }`; the path changes and the prices reload |
| The same product is chosen again | The path is unchanged, so nothing reloads                                      |
| The merchant cancels             | The picker answers `undefined`; nothing changes                                |
| The picker throws                | A dismissible banner "Couldn't open the product picker"                        |

The picker is Shopify's own, opened through the `shopify` global that App
Bridge provides. It makes no call to Laravel, and variants are hidden because
the preview prices the first variant only.

---

## 7. How the types flow

One type's journey from the server to the screen. Our frontend is everything
from `lib/types.ts` down.

```
   Laravel API Resource                 ───▶   Runtime JSON:
   toArray() defines the JSON                  never checked against T
              │
              │  copied by hand
              ▼
   lib/types.ts
   one type per API Resource
              │
              ▼
   useApi<T>() and api<T>()
   the caller names the body as T
              │
              ▼
   Page                                 ◀───   npm run typecheck:
   data arrives typed as T, or null            fails on a wrong field
              │
              ▼
   Component props
   interface Props, the same types
              │
              ▼
   Polaris components
   their own prop types
```

Using `Tier` as the example:

| Step | Where                                        | What it looks like                                                                      |
| ---- | -------------------------------------------- | --------------------------------------------------------------------------------------- |
| 1    | `TierSettingResource::toArray()`             | PHP returns `id`, `tag`, `name`, `discount_type`, `discount_value`, `badge_tone`        |
| 2    | [lib/types.ts](../resources/js/lib/types.ts) | `interface Tier { id: number; tag: string; name: string \| null; … }`, written to match |
| 3    | `useApi<Data<Tier[]>>('/tiers')`             | The page says "the body of this path is `{ data: Tier[] }`"                             |
| 4    | `Customers.tsx`                              | `tiersLoad.data` is `Data<Tier[]> \| null`, so `tiers` is `Tier[]`                      |
| 5    | `TierBadges`                                 | `interface Props { tags: string[]; tiers: Tier[] }`                                     |
| 6    | Polaris `<Badge tone={tier.badge_tone}>`     | `BadgeTone` is a union of the seven tone strings Polaris accepts                        |

**What the type checker guarantees.** From step 2 downwards, every use is
checked. `npm run typecheck` fails if:

- a page reads a field the type does not have, such as `customer.phone`;
- a page uses `data` without handling null (the hook returns null until the
  answer arrives);
- a component is given the wrong props, or a required prop is left out;
- a value Polaris does not accept is passed to it, such as a badge tone
  outside the union.

**What it does not guarantee.** There is one gap:

- **Step 1 to step 2 is by hand.** If someone adds or renames a field in a
  Resource and forgets `lib/types.ts`, nothing fails at build time.
- **`T` is a claim, not a check.** `api<T>()` ends with `return body as T`.
  If Laravel sends a different shape, TypeScript will not notice; the page
  will misbehave at runtime instead.

That is why `CLAUDE.md` says a Resource and its type change together.

Three other places types enter:

- **`ApiError`** in `lib/api.ts`. A `catch` gives `unknown` in strict mode,
  and `e instanceof ApiError` is what lets a page read `status` and `errors`.
- **The `shopify` global**, typed by the `@shopify/app-bridge-types` package,
  so the picker's options and its answer are checked.
- **Polaris's prop types.** `CustomerList` borrows
  `IndexTableProps['pagination']` directly, so the pagination object must
  match what Polaris expects.

**Why Vite alone is not enough.** Vite strips the types without checking
them, so a type error would still build. The separate `npm run typecheck`
step, which CI also runs, is the only thing that enforces any of this.
