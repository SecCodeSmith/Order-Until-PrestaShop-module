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
| Countdown label **(per language)** | `SCMOU_LABEL_COUNTDOWN` | — | prefix before the timer, e.g. "still" |
| Footnote `*` **(per language)** | `SCMOU_FOOTNOTE` | `* on working days` / `* w dni robocze` | small note under the box; empty = hidden |
| Cache TTL (server mode) | `SCMOU_CACHE_TTL` | `120` | seconds; `0` disables |
| API timeout (server mode) | `SCMOU_TIMEOUT` | `3` | seconds |
| Show on product / cart | `SCMOU_SHOW_PRODUCT` / `SCMOU_SHOW_CART` | on | |
| Auto-refresh at zero | `SCMOU_REFRESH` | on | refetch when the timer reaches zero |

### Text templates

The wording is built in the browser from the API's structured fields, so you can fully
customise it. Placeholders:

- `{cutoff}` — the order cutoff time, e.g. `14:00`
- `{when}` — `jutro` / `tomorrow` if delivery is the next day, otherwise the weekday
  (Polish uses the correct accusative: `we wtorek`, `w środę`, …)
- `{delivery}` — delivery date as `DD.MM`

Built-in defaults: PL `Dostępny. Zamów do {cutoff}, {when} u Ciebie*` ·
EN `Available. Order by {cutoff}, delivered {when}*`.

## Languages & translations

The widget is fully multi-language — the same page renders correct wording in each
shop language:

- **Sentence text**: the per-language `SCMOU_TPL_OPEN` / `SCMOU_TPL_CLOSED` fields
  (Back Office, one value per language). Leave empty to use the translatable default.
- **Day/date phrases** (`tomorrow`, weekday names, the `d` days unit, default templates):
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

## Files

```
scmorderuntil/
├─ scmorderuntil.php                     module (install, hooks, widget, config, server-proxy)
├─ config.xml
├─ logo.png                              module icon shown in the Back Office module list
├─ controllers/front/ajax.php            same-origin proxy (server call mode only)
├─ views/templates/hook/countdown.tpl    placeholder markup (no server-side API call)
├─ views/js/countdown.js                 client-side fetch + text builder + live countdown
├─ views/css/countdown.css               green box + countdown pill (light/dark)
├─ translations/pl.php                    Polish translations of the widget phrases
└─ index.php (+ per-folder stubs)
```

## Notes

- If the API is unreachable, the box stays hidden (fails silent) — no broken UI.
- CORS: the API sets `Access-Control-Allow-Origin` (configurable via `CORS_ALLOW_ORIGINS`).
  For client mode, keep the API open or restrict it at the network layer rather than with a
  browser-exposed key.
- All the working-day / holiday / cutoff logic lives in the FastAPI service; this module only
  presents the result.
