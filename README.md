# SCM Order Until — PrestaShop module (`scmorderuntil`)

Shows a green **"Available — order by HH:MM, delivered {tomorrow / weekday}"** box with a
live **countdown**, in the **product summary header** (next to the price) and on the cart.

To keep load **off** the PrestaShop server, the delivery estimate is fetched **client-side**:
the visitor's browser calls the companion **FastAPI service** directly
(`GET /api/v1/delivery/estimate`, CORS-enabled). PrestaShop only outputs a tiny placeholder
+ a small JS config — it does **not** call the API on every page render.

Compatible with **PrestaShop 1.7.x and 8.x**.

Example (Polish): **✓ Dostępny. Zamów do 14:00, jutro u Ciebie\*  ⟨01:59:59⟩**

## How it works

```
Product page (server) ── renders placeholder + window.scmou config  (no API call)
Browser (countdown.js) ── GET {API}/api/v1/delivery/estimate ──► FastAPI  (CORS)
        └─ builds the text, shows the green box, ticks the countdown every second
        └─ at zero (optional): refetch → roll over to the next order window
```

- **Client call mode (default)** — the browser talks to the API. Least load on PrestaShop.
  The API URL must be reachable from customers' browsers (a public URL).
- **Server call mode (fallback)** — the browser calls the module's own AJAX controller
  (`controllers/front/ajax.php`), which proxies to the API in PHP (with a short cache).
  Use this when the API cannot be exposed publicly.

## Install

1. Ensure the **FastAPI service** is running and reachable (publicly, for client mode).
2. Zip the `scmorderuntil/` folder and upload it in **Back Office → Modules → Upload a
   module** (or copy it into `<prestashop>/modules/` and install from the list).
3. Open **Configure** and set at least the **API base URL**. Everything else has sensible
   defaults.

## Configuration

| Setting | Key | Default | Notes |
|--------|-----|---------|-------|
| API base URL | `SCMOU_API_URL` | `http://localhost:8000` | Client mode: must be browser-reachable |
| Call mode | `SCMOU_CALL_MODE` | `client` | `client` (browser) or `server` (PHP proxy) |
| API key | `SCMOU_API_KEY` | — | `X-API-Key`; in client mode it's exposed — prefer an open API |
| Placement (product) | `SCMOU_PLACEMENT` | `price_block` | `price_block` (summary header) or `additional_info` (under price) |
| Cutoff time | `SCMOU_CUTOFF` | — | `HH:MM`; empty = API default |
| Delivery offset | `SCMOU_DELIVERY_OFFSET` | — | working days ship→delivery; empty = API default |
| API message locale | `SCMOU_LOCALE` | auto | `''` (page language) / `en` / `pl` — only the API request's `locale` param; the on-screen wording comes from PrestaShop translations |
| Text — open **(per language)** | `SCMOU_TPL_OPEN` | built-in | placeholders `{cutoff} {when} {delivery}` |
| Text — closed **(per language)** | `SCMOU_TPL_CLOSED` | built-in | placeholders `{cutoff} {when} {delivery}` |
| Text — second line **(per language)** | `SCMOU_TPL_SUB` | built-in | detail line under the main text; placeholders `{cutoff} {shipwhen} {when} {ship} {delivery}` |
| Countdown label **(per language)** | `SCMOU_LABEL_COUNTDOWN` | — | prefix before the timer, e.g. "still" |
| Footnote `*` **(per language)** | `SCMOU_FOOTNOTE` | `* on working days` / `* w dni robocze` | small note under the box; empty = hidden |
| Cache TTL (server mode) | `SCMOU_CACHE_TTL` | `120` | seconds; `0` disables |
| API timeout (server mode) | `SCMOU_TIMEOUT` | `3` | seconds |
| Show on product / cart | `SCMOU_SHOW_PRODUCT` / `SCMOU_SHOW_CART` | on | |
| Show second line | `SCMOU_SHOW_SUB` | on | the ship/delivery detail line under the main text |
| Auto-refresh at zero | `SCMOU_REFRESH` | on | refetch when the timer reaches zero |
| Update source | `SCMOU_UPDATE_REPO` | `SecCodeSmith/Order-Until-PrestaShop-module` | GitHub `owner/repo` the self-updater reads releases from |
| Update token | `SCMOU_UPDATE_TOKEN` | — | optional GitHub token (private repo / rate limits) |

### Text templates

The wording is built in the browser from the API's structured fields, so you can fully
customise it. Placeholders:

- `{cutoff}` — the order cutoff time, e.g. `14:00`
- `{when}` — `jutro` / `tomorrow` if delivery is the next day, otherwise the weekday
  (Polish uses the correct accusative: `we wtorek`, `w środę`, …)
- `{delivery}` — delivery date as `DD.MM`
- `{shipwhen}` — `today` / `tomorrow` / weekday for the **ship** day (second line only)
- `{ship}` — ship date as `DD.MM` (second line only)

Built-in defaults: PL `Dostępny. Zamów do {cutoff}, {when} u Ciebie*` ·
EN `Available. Order by {cutoff}, delivered {when}*`.

**Second line** (`SCMOU_TPL_SUB`, toggle `SCMOU_SHOW_SUB`) adds a detail line below the
main text — by default the ship day, which the first line doesn't show:
PL `Zamów do {cutoff}, wysyłka {shipwhen}, dostawa {when}` ·
EN `Order by {cutoff}, ships {shipwhen}, delivered {when}` →
e.g. *"Order by 15:00, ships tomorrow, delivered on Monday"*.

## Languages & translations

The widget is fully multi-language — the same page renders correct wording in each
shop language:

- **Sentence text**: the per-language `SCMOU_TPL_OPEN` / `SCMOU_TPL_CLOSED` fields
  (Back Office, one value per language). Leave empty to use the translatable default.
- **Day/date phrases** (`today`, `tomorrow`, weekday names, the `d` days unit, default templates):
  these live in the module as `$this->l()` strings and are translated per language via
  PrestaShop's translation system. They are injected into `window.scmou.labels` for the
  current page language, so the JS is language-agnostic.
  - A **Polish** translation ships in `translations/pl.php` (with correct grammar, e.g.
    `we wtorek`, `w środę`).
  - For any other language, translate them in **Back Office → International → Translations
    → Installed modules translations** (type: Modules, select the language), or drop a
    `translations/<iso>.php` file.

Example — same product, two languages:

```
/men/1-1-hummingbird-printed-t-shirt.html      → "…delivered tomorrow*"
/pl/men/1-1-hummingbird-printed-t-shirt.html   → "…jutro u Ciebie*"
```

## Placement

- **Product summary header** (default): hook `displayProductPriceBlock` — renders once at the
  top of the price block, right by the price. Gated to the **product detail page** so it does
  not leak into product-list miniatures, the add-to-cart modal or cart line items (that hook
  fires in all of those).
- **Under the price block**: switch *Placement* to `additional_info`
  (hook `displayProductAdditionalInfo`).
- **Cart**: hook `displayReassurance` — renders inside the **order-summary column**, next to the
  "Proceed to checkout" button. Gated to the cart controller and to a single render per page.
- **Anywhere**: `WidgetInterface` — drop `{widget name='scmorderuntil'}` into any template.

## Styling

British Racing Green (`#004225`) box by default. Override `views/css/countdown.css` or target: `.scmou-box`
(`.scmou-open` / `.scmou-closed`), `.scmou-check`, `.scmou-text`, `.scmou-badge`
(the countdown pill). Dark-mode aware.

## Updating from GitHub

The module can update itself from its GitHub releases — no manual re-upload:

1. **Modules → SCM Order Until → Configure → "Module updates"**.
2. Click **Check for updates**. It queries the latest release of
   `SecCodeSmith/Order-Until-PrestaShop-module` (configurable via `SCMOU_UPDATE_REPO`).
3. If a newer version exists, click **Download & install** — the module downloads the
   release's `scmorderuntil.zip`, verifies it, extracts it over `modules/scmorderuntil/`,
   and reloads so PrestaShop runs the upgrade scripts. The DB upgrade finishes on that reload.

Safeguards: the asset URL must be on `github.com` under the configured repo's
`releases/download/` path; the zip must contain `scmorderuntil/scmorderuntil.php` and no
entry may escape the `scmorderuntil/` folder (Zip-Slip guard). Set `SCMOU_UPDATE_TOKEN` for a
private repo or to avoid GitHub's unauthenticated API rate limit. Requires PHP's `zip`
extension and write access to `modules/`.

## Files

```
scmorderuntil/
├─ scmorderuntil.php                     module (install, hooks, widget, config, server-proxy)
├─ config.xml
├─ logo.png                              module icon shown in the Back Office module list
├─ controllers/front/ajax.php            same-origin proxy (server call mode only)
├─ views/templates/hook/countdown.tpl    placeholder markup (no server-side API call)
├─ views/js/countdown.js                 client-side fetch + text builder + live countdown
├─ views/css/countdown.css               British Racing Green box + countdown pill (light/dark)
├─ upgrade/upgrade-1.3.0.php              hook migration for already-installed shops
├─ translations/pl.php                    Polish translations of the widget phrases
└─ index.php (+ per-folder stubs)
```

## Releasing

The **module's own version is the single source of truth** — `$this->version` in
`scmorderuntil.php` (mirrored in `config.xml`). On every push to `main`/`master`, CI
(`.github/workflows/ci.yml`, job `tag-and-release`):

1. Reads that version and bumps the **patch** (`scripts/bump_version.py --write`), writing
   the new value back into `scmorderuntil.php` + `config.xml`.
2. Commits the bump back to the branch (`[skip ci]`, so it does not loop) and creates the
   matching `vX.Y.Z` git tag.
3. Packages `scmorderuntil.zip` and publishes a GitHub Release with it attached.

So `1.3.0` in the module ships as **`v1.3.1`**. To cut a **minor/major** release, edit
`$this->version` in `scmorderuntil.php` (e.g. to `1.4.0`) and push — the next run releases
`v1.4.1`. A collision guard fails the job if the computed tag already exists.

## Notes

- If the API is unreachable, the box stays hidden (fails silent) — no broken UI.
- CORS: the API sets `Access-Control-Allow-Origin` (configurable via `CORS_ALLOW_ORIGINS`).
  For client mode, keep the API open or restrict it at the network layer rather than with a
  browser-exposed key.
- All the working-day / holiday / cutoff logic lives in the FastAPI service; this module only
  presents the result.
