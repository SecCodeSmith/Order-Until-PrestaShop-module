<?php
/**
 * SCM Order Until — PrestaShop module.
 *
 * Shows a green "Available — order by HH:MM, delivered tomorrow" box with a live
 * countdown on the product page (near the price) and inside the cart's order-
 * summary column (next to the "Proceed to checkout" button).
 *
 * To keep load OFF the PrestaShop server, the delivery estimate is fetched
 * CLIENT-SIDE: the browser calls the FastAPI service directly
 * (GET /api/v1/delivery/estimate, CORS-enabled). PrestaShop only outputs a tiny
 * placeholder + config; it does not call the API on every page render.
 *
 * A "server" call mode (PHP proxy via controllers/front/ajax.php) is still
 * available as a fallback for shops that cannot expose the API publicly.
 *
 * Compatible with PrestaShop 1.7.x and 8.x.
 *
 * @author  SCM Order Until
 * @license MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Module\WidgetInterface;

class Scmorderuntil extends Module implements WidgetInterface
{
    const PREFIX = 'SCMOU_';

    /** Guard so displayProductPriceBlock renders only once per page. */
    protected static $priceBlockRendered = false;

    /** Guard so the cart-summary (reassurance) box renders only once per page. */
    protected static $cartSummaryRendered = false;

    public function __construct()
    {
        $this->name = 'scmorderuntil';
        $this->tab = 'front_office_features';
        $this->version = '1.3.1';
        $this->author = 'SCM Order Until';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('SCM Order Until — Delivery Countdown');
        $this->description = $this->l(
            'Green "order by cutoff, delivered next working day" box with a live '
            . 'countdown, fetched client-side from the SCM Order Until API.'
        );
        $this->confirmUninstall = $this->l('Remove SCM Order Until configuration?');
    }

    // --------------------------------------------------------------------- //
    // Install / uninstall
    // --------------------------------------------------------------------- //
    public function install()
    {
        return parent::install()
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayProductPriceBlock')
            && $this->registerHook('displayProductAdditionalInfo')
            && $this->registerHook('displayReassurance')
            && $this->installDefaults();
    }

    public function uninstall()
    {
        foreach ($this->configKeys() as $k) {
            Configuration::deleteByName(self::PREFIX . $k);
        }
        return parent::uninstall();
    }

    private function configKeys()
    {
        return [
            'API_URL', 'API_KEY', 'CALL_MODE', 'PLACEMENT', 'CUTOFF',
            'DELIVERY_OFFSET', 'LOCALE', 'TPL_OPEN', 'TPL_CLOSED', 'LABEL_COUNTDOWN',
            'FOOTNOTE', 'CACHE_TTL', 'TIMEOUT', 'SHOW_PRODUCT', 'SHOW_CART', 'REFRESH',
        ];
    }

    /** Config keys stored per-language (multilang). */
    private function multilangKeys()
    {
        return ['TPL_OPEN', 'TPL_CLOSED', 'LABEL_COUNTDOWN', 'FOOTNOTE'];
    }

    /** Built-in per-ISO default for a multilang key (used at install time). */
    private function multilangDefault($key, $iso)
    {
        $d = [
            'FOOTNOTE' => ['pl' => '* w dni robocze', 'en' => '* on working days'],
        ];
        // Text templates & countdown label default to empty -> JS localized default.
        return isset($d[$key][$iso]) ? $d[$key][$iso] : '';
    }

    private function installDefaults()
    {
        // Allow the initial API URL to be seeded from the environment (handy for
        // Docker, where the service is reachable as e.g. http://api:8000).
        $defaultApiUrl = getenv('SCMOU_API_URL') ?: 'http://localhost:8000';
        $defaults = [
            'API_URL' => $defaultApiUrl,
            'API_KEY' => '',
            'CALL_MODE' => 'client',      // client = browser fetch (low PS impact)
            'PLACEMENT' => 'price_block', // header of the product summary
            'CUTOFF' => '',               // empty = use API default
            'DELIVERY_OFFSET' => '',      // empty = use API default
            'LOCALE' => '',               // empty = auto (shop language)
            'CACHE_TTL' => 120,           // server mode only
            'TIMEOUT' => 3,               // server mode only
            'SHOW_PRODUCT' => 1,
            'SHOW_CART' => 1,
            'REFRESH' => 1,
        ];
        foreach ($defaults as $k => $v) {
            Configuration::updateValue(self::PREFIX . $k, $v);
        }

        // Multilang keys: seed a value for every installed language.
        $langs = Language::getLanguages(false);
        foreach ($this->multilangKeys() as $key) {
            $perLang = [];
            foreach ($langs as $lang) {
                $iso = isset($lang['iso_code']) ? $lang['iso_code'] : 'en';
                $perLang[(int) $lang['id_lang']] = $this->multilangDefault($key, $iso);
            }
            Configuration::updateValue(self::PREFIX . $key, $perLang);
        }
        return true;
    }

    private function conf($key, $default = null)
    {
        $val = Configuration::get(self::PREFIX . $key);
        return ($val === false || $val === null || $val === '') ? $default : $val;
    }

    /** Read a multilang config value for the current context language. */
    private function confLang($key, $default = '')
    {
        $idLang = (int) $this->context->language->id;
        $val = Configuration::get(self::PREFIX . $key, $idLang);
        return ($val === false || $val === null) ? $default : (string) $val;
    }

    // --------------------------------------------------------------------- //
    // Hooks
    // --------------------------------------------------------------------- //
    public function hookDisplayHeader()
    {
        $this->context->controller->registerStylesheet(
            'scmou-css',
            'modules/' . $this->name . '/views/css/countdown.css',
            ['media' => 'all', 'priority' => 150]
        );
        $this->context->controller->registerJavascript(
            'scmou-js',
            'modules/' . $this->name . '/views/js/countdown.js',
            ['position' => 'bottom', 'priority' => 150]
        );

        Media::addJsDef(['scmou' => $this->jsConfig()]);
    }

    /** Renders in the product summary header (near price). Fires once. */
    public function hookDisplayProductPriceBlock($params)
    {
        // displayProductPriceBlock also fires inside product miniatures on
        // listing pages, the add-to-cart modal and cart line items. Restrict it
        // to the product detail page so the box does not leak into those spots.
        if (!$this->isProductPage()) {
            return '';
        }
        if ($this->conf('PLACEMENT', 'price_block') !== 'price_block') {
            return '';
        }
        if (!$this->conf('SHOW_PRODUCT', 1) || self::$priceBlockRendered) {
            return '';
        }
        self::$priceBlockRendered = true;
        return $this->renderCountdown('product');
    }

    /** Alternative product placement (under the price block). */
    public function hookDisplayProductAdditionalInfo($params)
    {
        if (!$this->isProductPage()) {
            return '';
        }
        if ($this->conf('PLACEMENT', 'price_block') !== 'additional_info') {
            return '';
        }
        if (!$this->conf('SHOW_PRODUCT', 1)) {
            return '';
        }
        return $this->renderCountdown('product');
    }

    /**
     * Renders inside the cart's order-summary column — the reassurance block,
     * right by the "Proceed to checkout" button. Gated to the cart controller so
     * it does not follow the reassurance hook onto product pages, and guarded so
     * it shows only once even if the theme emits the hook more than once.
     */
    public function hookDisplayReassurance($params)
    {
        if (!$this->conf('SHOW_CART', 1) || self::$cartSummaryRendered) {
            return '';
        }
        if (!$this->isCartPage()) {
            return '';
        }
        self::$cartSummaryRendered = true;
        return $this->renderCountdown('cart');
    }

    /** True only on the product detail page (not listings, cart or modals). */
    private function isProductPage()
    {
        $controller = isset($this->context->controller) ? $this->context->controller : null;
        return $controller !== null
            && isset($controller->php_self)
            && $controller->php_self === 'product';
    }

    /** True only on the cart page (where the order-summary column lives). */
    private function isCartPage()
    {
        $controller = isset($this->context->controller) ? $this->context->controller : null;
        return $controller !== null
            && isset($controller->php_self)
            && $controller->php_self === 'cart';
    }

    // --------------------------------------------------------------------- //
    // Widget interface (place anywhere via {widget name='scmorderuntil'})
    // --------------------------------------------------------------------- //
    public function renderWidget($hookName, array $configuration)
    {
        return $this->renderCountdown('widget');
    }

    public function getWidgetVariables($hookName, array $configuration)
    {
        return [];
    }

    // --------------------------------------------------------------------- //
    // Rendering — a lightweight placeholder; JS fills it in client-side.
    // --------------------------------------------------------------------- //
    private function renderCountdown($context)
    {
        $this->context->smarty->assign([
            'scmou_context' => $context,
            'scmou_cutoff' => (string) $this->conf('CUTOFF', ''),
            'scmou_offset' => (string) $this->conf('DELIVERY_OFFSET', ''),
            'scmou_locale' => $this->resolveLocale(),
            'scmou_footnote' => $this->confLang('FOOTNOTE'),
        ]);

        return $this->fetch(
            'module:' . $this->name . '/views/templates/hook/countdown.tpl'
        );
    }

    /** JS config injected once via Media::addJsDef. */
    private function jsConfig()
    {
        $mode = $this->conf('CALL_MODE', 'client');
        $apiBase = rtrim((string) $this->conf('API_URL', ''), '/');

        $cfg = [
            'callMode' => $mode,
            'endpoint' => '/api/v1/delivery/estimate',
            'apiBase' => $apiBase,
            // Same-origin proxy used only in server mode.
            'ajaxUrl' => $this->context->link->getModuleLink(
                $this->name, 'ajax', ['action' => 'estimate', 'ajax' => 1]
            ),
            'cutoff' => (string) $this->conf('CUTOFF', ''),
            'offset' => (string) $this->conf('DELIVERY_OFFSET', ''),
            'locale' => $this->resolveLocale(),
            'refresh' => (bool) $this->conf('REFRESH', 1),
            'templates' => [
                'open' => $this->confLang('TPL_OPEN'),
                'closed' => $this->confLang('TPL_CLOSED'),
            ],
            'countdownLabel' => $this->confLang('LABEL_COUNTDOWN'),
            // Day/date phrases, translated via PrestaShop for the CURRENT page
            // language (so a multi-language shop renders each language correctly).
            'labels' => $this->phraseLabels(),
        ];

        // Only expose the key to the browser if the merchant opted into client
        // mode *and* set one (exposing it is their explicit choice).
        $apiKey = (string) $this->conf('API_KEY', '');
        if ($mode === 'client' && $apiKey !== '') {
            $cfg['apiKey'] = $apiKey;
        }
        return $cfg;
    }

    private function resolveLocale()
    {
        $locale = $this->conf('LOCALE', '');
        if ($locale) {
            return $locale;
        }
        $iso = isset($this->context->language->iso_code)
            ? $this->context->language->iso_code : 'en';
        return in_array($iso, ['pl', 'en'], true) ? $iso : 'en';
    }

    /**
     * Day/date phrases used to build the widget text, translated for the current
     * page language via PrestaShop's translation system ($this->l). These used to
     * be hardcoded (pl/en) in countdown.js; moving them here lets a shop with any
     * number of languages render each one correctly (translate in Back Office →
     * International → Translations → Installed module translations, or ship a
     * translations/<iso>.php file — a Polish one is included).
     *
     * weekdays is indexed 0=Sunday .. 6=Saturday to match JavaScript getDay().
     */
    private function phraseLabels()
    {
        return [
            'tomorrow' => $this->l('tomorrow'),
            'days' => $this->l('d'),
            'weekdays' => [
                $this->l('on Sunday'),
                $this->l('on Monday'),
                $this->l('on Tuesday'),
                $this->l('on Wednesday'),
                $this->l('on Thursday'),
                $this->l('on Friday'),
                $this->l('on Saturday'),
            ],
            // Fallback sentence templates when the per-language TPL_* fields are
            // empty. Also translatable, so they follow the page language.
            'defaultOpen' => $this->l('Available. Order by {cutoff}, delivered {when}*'),
            'defaultClosed' => $this->l('Available. Order by {cutoff}, delivered {when}*'),
        ];
    }

    // --------------------------------------------------------------------- //
    // Server call-mode proxy (used by controllers/front/ajax.php)
    // --------------------------------------------------------------------- //
    /**
     * Fetch the delivery estimate from the FastAPI service server-side, with a
     * short file cache. Used ONLY in 'server' call mode. Returns an assoc array
     * or null on failure.
     */
    public function getDeliveryEstimate()
    {
        $ttl = (int) $this->conf('CACHE_TTL', 120);
        $locale = $this->resolveLocale();
        $cutoff = $this->conf('CUTOFF', '');
        $offset = $this->conf('DELIVERY_OFFSET', '');

        $cacheKey = md5($locale . '|' . $cutoff . '|' . $offset);
        $cacheFile = _PS_CACHE_DIR_ . 'scmorderuntil_' . $cacheKey . '.json';

        if ($ttl > 0 && is_file($cacheFile)
            && (time() - filemtime($cacheFile)) < $ttl) {
            $cached = json_decode((string) Tools::file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = $this->requestEstimate($locale, $cutoff, $offset);
        if ($data !== null && $ttl > 0) {
            @file_put_contents($cacheFile, json_encode($data));
        }
        return $data;
    }

    private function requestEstimate($locale, $cutoff, $offset)
    {
        $base = rtrim((string) $this->conf('API_URL', ''), '/');
        if ($base === '') {
            return null;
        }
        $query = ['locale' => $locale];
        if ($cutoff !== '' && $cutoff !== null) {
            $query['cutoff'] = $cutoff;
        }
        if ($offset !== '' && $offset !== null) {
            $query['delivery_offset'] = (int) $offset;
        }
        $url = $base . '/api/v1/delivery/estimate?' . http_build_query($query);

        $timeout = (int) $this->conf('TIMEOUT', 3);
        $apiKey = (string) $this->conf('API_KEY', '');

        $raw = $this->httpGet($url, $apiKey, $timeout);
        if ($raw === null) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** Minimal GET with optional X-API-Key. cURL, falling back to streams. */
    private function httpGet($url, $apiKey, $timeout)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => array_filter([
                    'Accept: application/json',
                    $apiKey !== '' ? 'X-API-Key: ' . $apiKey : null,
                ]),
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body !== false && $code >= 200 && $code < 300) {
                return $body;
            }
            return null;
        }

        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'header' => "Accept: application/json\r\n"
                . ($apiKey !== '' ? 'X-API-Key: ' . $apiKey . "\r\n" : ''),
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }

    // --------------------------------------------------------------------- //
    // Back-office configuration
    // --------------------------------------------------------------------- //
    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitScmou')) {
            $this->postProcessConfig();
            $output .= $this->displayConfirmation($this->l('Settings updated.'));
        }
        return $output . $this->renderConfigForm();
    }

    private function postProcessConfig()
    {
        $types = [
            'API_URL' => 'string', 'API_KEY' => 'string', 'CALL_MODE' => 'string',
            'PLACEMENT' => 'string', 'CUTOFF' => 'string', 'DELIVERY_OFFSET' => 'int',
            'LOCALE' => 'string', 'CACHE_TTL' => 'int', 'TIMEOUT' => 'int',
            'SHOW_PRODUCT' => 'bool', 'SHOW_CART' => 'bool', 'REFRESH' => 'bool',
        ];
        foreach ($types as $key => $type) {
            $raw = Tools::getValue(self::PREFIX . $key);
            if ($type === 'int') {
                $val = ($raw === '' || $raw === false) ? '' : (int) $raw;
            } elseif ($type === 'bool') {
                $val = (int) (bool) $raw;
            } else {
                $val = trim((string) $raw);
            }
            Configuration::updateValue(self::PREFIX . $key, $val);
        }

        // Multilang keys: collect one value per installed language.
        foreach ($this->multilangKeys() as $key) {
            $perLang = [];
            foreach (Language::getLanguages(false) as $lang) {
                $id = (int) $lang['id_lang'];
                $perLang[$id] = trim((string) Tools::getValue(self::PREFIX . $key . '_' . $id));
            }
            Configuration::updateValue(self::PREFIX . $key, $perLang);
        }

        foreach ((array) glob(_PS_CACHE_DIR_ . 'scmorderuntil_*.json') as $f) {
            @unlink($f);
        }
    }

    private function renderConfigForm()
    {
        $onoff = function ($label, $name, $desc = '') {
            return [
                'type' => 'switch', 'label' => $label, 'name' => self::PREFIX . $name,
                'is_bool' => true, 'desc' => $desc,
                'values' => [
                    ['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Yes')],
                    ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')],
                ],
            ];
        };

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('SCM Order Until settings'),
                    'icon' => 'icon-truck',
                ],
                'input' => [
                    [
                        'type' => 'text', 'label' => $this->l('API base URL'),
                        'name' => self::PREFIX . 'API_URL', 'required' => true,
                        'desc' => $this->l('In client mode this must be reachable from customers\' browsers (public URL).'),
                    ],
                    [
                        'type' => 'select', 'label' => $this->l('Call mode'),
                        'name' => self::PREFIX . 'CALL_MODE',
                        'desc' => $this->l('Client = browser calls the API directly (least load on PrestaShop). Server = PHP proxy.'),
                        'options' => ['query' => [
                            ['id' => 'client', 'name' => $this->l('Client (browser, recommended)')],
                            ['id' => 'server', 'name' => $this->l('Server (PHP proxy)')],
                        ], 'id' => 'id', 'name' => 'name'],
                    ],
                    [
                        'type' => 'text', 'label' => $this->l('API key (X-API-Key)'),
                        'name' => self::PREFIX . 'API_KEY',
                        'desc' => $this->l('Only if the API requires it. In client mode this is exposed to the browser — prefer an open/public API.'),
                    ],
                    [
                        'type' => 'select', 'label' => $this->l('Placement (product page)'),
                        'name' => self::PREFIX . 'PLACEMENT',
                        'options' => ['query' => [
                            ['id' => 'price_block', 'name' => $this->l('Summary header (near price)')],
                            ['id' => 'additional_info', 'name' => $this->l('Under the price block')],
                        ], 'id' => 'id', 'name' => 'name'],
                    ],
                    [
                        'type' => 'text', 'label' => $this->l('Cutoff time (HH:MM)'),
                        'name' => self::PREFIX . 'CUTOFF',
                        'desc' => $this->l('Leave empty to use the API default.'),
                    ],
                    [
                        'type' => 'text', 'label' => $this->l('Delivery offset (working days)'),
                        'name' => self::PREFIX . 'DELIVERY_OFFSET',
                        'desc' => $this->l('Leave empty to use the API default.'),
                    ],
                    [
                        'type' => 'select', 'label' => $this->l('Message language'),
                        'name' => self::PREFIX . 'LOCALE',
                        'options' => ['query' => [
                            ['id' => '', 'name' => $this->l('Auto (shop language)')],
                            ['id' => 'en', 'name' => 'English'],
                            ['id' => 'pl', 'name' => 'Polski'],
                        ], 'id' => 'id', 'name' => 'name'],
                    ],
                    [
                        'type' => 'text', 'lang' => true,
                        'label' => $this->l('Text — order window open'),
                        'name' => self::PREFIX . 'TPL_OPEN',
                        'desc' => $this->l('Placeholders: {cutoff} {when} {delivery}. Empty = built-in default.'),
                    ],
                    [
                        'type' => 'text', 'lang' => true,
                        'label' => $this->l('Text — window closed'),
                        'name' => self::PREFIX . 'TPL_CLOSED',
                        'desc' => $this->l('Placeholders: {cutoff} {when} {delivery}. Empty = built-in default.'),
                    ],
                    [
                        'type' => 'text', 'lang' => true,
                        'label' => $this->l('Countdown label'),
                        'name' => self::PREFIX . 'LABEL_COUNTDOWN',
                        'desc' => $this->l('Prefix before the timer, e.g. "still". Empty = built-in default.'),
                    ],
                    [
                        'type' => 'textarea', 'lang' => true, 'rows' => 2, 'cols' => 60,
                        'label' => $this->l('Footnote (* under the box)'),
                        'name' => self::PREFIX . 'FOOTNOTE',
                        'desc' => $this->l('Small note shown below the box, e.g. the "*" explanation. Leave empty to hide it.'),
                    ],
                    [
                        'type' => 'text', 'label' => $this->l('Cache TTL (seconds, server mode)'),
                        'name' => self::PREFIX . 'CACHE_TTL',
                    ],
                    [
                        'type' => 'text', 'label' => $this->l('API timeout (seconds, server mode)'),
                        'name' => self::PREFIX . 'TIMEOUT',
                    ],
                    $onoff($this->l('Show on product page'), 'SHOW_PRODUCT'),
                    $onoff($this->l('Show on cart page'), 'SHOW_CART'),
                    $onoff(
                        $this->l('Auto-refresh at zero'), 'REFRESH',
                        $this->l('Refetch when the countdown reaches zero so it rolls to the next window.')
                    ),
                ],
                'submit' => ['title' => $this->l('Save'), 'name' => 'submitScmou'],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex
            . '&configure=' . $this->name;
        $helper->submit_action = 'submitScmou';

        // Enable the per-language flag UI for multilang inputs.
        $helper->languages = $this->context->controller->getLanguages();
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang =
            (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');

        $multilang = $this->multilangKeys();
        $languages = Language::getLanguages(false);

        $values = [];
        foreach ($this->configKeys() as $k) {
            if (in_array($k, $multilang, true)) {
                foreach ($languages as $lang) {
                    $id = (int) $lang['id_lang'];
                    $values[self::PREFIX . $k][$id] =
                        Configuration::get(self::PREFIX . $k, $id);
                }
            } else {
                $values[self::PREFIX . $k] = Configuration::get(self::PREFIX . $k);
            }
        }
        $helper->fields_value = $values;

        return $helper->generateForm([$fields_form]);
    }
}
