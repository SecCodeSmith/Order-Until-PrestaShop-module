# Contributing — SCM Order Until (PrestaShop module)

Quality gates for this repo are enforced by [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

| Check | Command | CI gate |
|-------|---------|---------|
| PHP syntax | `find . -name '*.php' -print0 \| xargs -0 -n1 php -l` | **required** |
| PHP style (PSR-12) | `phpcs --standard=phpcs.xml.dist` | advisory |
| JS style | `npm install && npm run lint:js` | advisory |

## Rules

- Target **PrestaShop 1.7.x and 8.x**; every entry file guards with
  `if (!defined('_PS_VERSION_')) { exit; }`.
- **PSR-12** style (see [`phpcs.xml.dist`](phpcs.xml.dist)); 4-space indent.
- **Escape all Smarty output** (`|escape:'html':'UTF-8'`).
- The browser JS never hardcodes the internal API URL: `client` mode uses the configured
  public API base; `server` mode calls the module's own AJAX front controller, which proxies.
- User-facing strings go through `$this->l()` and `translations/<iso>.php` — no hardcoded
  per-language text in the JS.
- The countdown JS ([`views/js/countdown.js`](views/js/countdown.js)) is **ES5**, IIFE-scoped
  and language-agnostic (all wording comes from `window.scmou.labels`). Lint config:
  [`.eslintrc.json`](.eslintrc.json).
