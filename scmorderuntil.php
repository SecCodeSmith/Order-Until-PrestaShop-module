<?php
/**
 * SCM Order Until — PrestaShop module.
 *
 * Shows a green "Available, order by HH:MM, delivery tomorrow" box with a live
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

    /** Default GitHub repo (owner/name) the self-updater pulls releases from. */
    const GH_DEFAULT_REPO = 'SecCodeSmith/Order-Until-PrestaShop-module';

    /** Release asset the updater downloads (built by the module's CI). */
    const GH_ASSET = 'scmorderuntil.zip';

    /** Guard so displayProductPriceBlock renders only once per page. */
    protected static $priceBlockRendered = false;

    /** Guard so the cart-summary (reassurance) box renders only once per page. */
    protected static $cartSummaryRendered = false;

    public function __construct()
    {
        $this->name = 'scmorderuntil';
        $this->tab = 'front_office_features';
        $this->version = '1.3.2';
        $this->author = 'SCM Order Until';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('SCM Order Until — Delivery Countdown');
        $this->description = $this->l(
            'Green "order by cutoff, delivery next working day" box with a live '
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
            'DELIVERY_OFFSET', 'LOCALE', 'TPL_OPEN', 'TPL_CLOSED',
            'LABEL_COUNTDOWN', 'FOOTNOTE', 'CACHE_TTL', 'TIMEOUT', 'SHOW_PRODUCT',
            'SHOW_CART', 'REFRESH', 'UPDATE_REPO', 'UPDATE_TOKEN',
            // Legacy (removed second-line feature): listed only so uninstall
            // purges any values left in the DB by older module versions.
            'TPL_SUB', 'SHOW_SUB',
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
            'UPDATE_REPO' => self::GH_DEFAULT_REPO,
            'UPDATE_TOKEN' => '',
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
            'today' => $this->l('today'),
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
            'defaultOpen' => $this->l('Available, order by {cutoff}, delivery {when}*'),
            'defaultClosed' => $this->l('Available, order before {shipwhen} {cutoff}*'),
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
    // Self-update from the GitHub release package
    // --------------------------------------------------------------------- //
    /** Configured "owner/repo" the updater reads releases from. */
    private function updateRepo()
    {
        $repo = trim((string) $this->conf('UPDATE_REPO', self::GH_DEFAULT_REPO));
        return $repo !== '' ? $repo : self::GH_DEFAULT_REPO;
    }

    /**
     * Fetch the latest published release for the module repo.
     * Returns ['tag','version','zip','html','notes'] or null on failure.
     */
    public function fetchLatestRelease()
    {
        $url = 'https://api.github.com/repos/' . $this->updateRepo() . '/releases/latest';
        $raw = $this->ghGet($url);
        if ($raw === null) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $this->parseRelease($data) : null;
    }

    /** Turn a GitHub release JSON payload into our compact release array (or null). */
    private function parseRelease(array $data)
    {
        if (empty($data['tag_name'])) {
            return null;
        }
        $zip = '';
        if (!empty($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (isset($asset['name'], $asset['browser_download_url'])
                    && $asset['name'] === self::GH_ASSET) {
                    $zip = (string) $asset['browser_download_url'];
                    break;
                }
            }
        }
        return [
            'tag' => (string) $data['tag_name'],
            'version' => ltrim((string) $data['tag_name'], 'vV'),
            'zip' => $zip,
            'html' => isset($data['html_url']) ? (string) $data['html_url'] : '',
            'notes' => isset($data['body']) ? (string) $data['body'] : '',
        ];
    }

    /** GET a GitHub URL with the required User-Agent (+ optional token), following redirects. */
    private function ghGet($url, $binary = false)
    {
        $headers = [
            'User-Agent: scmorderuntil-updater',
            'Accept: ' . ($binary ? 'application/octet-stream' : 'application/vnd.github+json'),
        ];
        $token = trim((string) $this->conf('UPDATE_TOKEN', ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $binary ? 90 : 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($body !== false && $code >= 200 && $code < 300) ? $body : null;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $binary ? 90 : 15,
                'follow_location' => 1,
                'header' => implode("\r\n", $headers) . "\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }

    /** Only trust asset URLs on github.com under the configured repo's release path. */
    private function isTrustedAssetUrl($url)
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $prefix = '/' . $this->updateRepo() . '/releases/download/';
        return $host === 'github.com' && strpos($path, $prefix) === 0;
    }

    /**
     * Download the latest release zip and extract it over modules/scmorderuntil/.
     * PrestaShop runs the actual DB upgrade on the following page load (the caller
     * redirects). Returns ['ok'=>bool,'message'=>string,'version'=>string].
     */
    public function performSelfUpdate()
    {
        $release = $this->fetchLatestRelease();
        if ($release === null) {
            return ['ok' => false, 'message' => $this->l('Could not reach GitHub to fetch the latest release.')];
        }
        if (version_compare($release['version'], $this->version, '<=')) {
            return ['ok' => false, 'message' => $this->l('You are already running the latest version.')];
        }
        if ($release['zip'] === '' || !$this->isTrustedAssetUrl($release['zip'])) {
            return ['ok' => false, 'message' => $this->l('No trusted update package (scmorderuntil.zip) on the release.')];
        }

        $binary = $this->ghGet($release['zip'], true);
        if ($binary === null || Tools::strlen($binary) < 100) {
            return ['ok' => false, 'message' => $this->l('Failed to download the update package.')];
        }

        $tmpZip = _PS_CACHE_DIR_ . 'scmorderuntil_update_' . time() . '.zip';
        if (@file_put_contents($tmpZip, $binary) === false) {
            return ['ok' => false, 'message' => $this->l('Could not write the update package to disk.')];
        }

        $result = $this->extractUpdateZip($tmpZip);
        @unlink($tmpZip);
        if ($result !== true) {
            return ['ok' => false, 'message' => $result];
        }

        $this->cleanCaches();
        return [
            'ok' => true,
            'version' => $release['version'],
            'message' => sprintf($this->l('Updated to %s.'), $release['version']),
        ];
    }

    /**
     * Validate then extract the update zip over the modules directory. Every entry
     * must live under scmorderuntil/ with no path traversal, and the module main
     * file must be present. Returns true on success or an error string.
     */
    private function extractUpdateZip($tmpZip)
    {
        if (!class_exists('ZipArchive')) {
            return $this->l('ZipArchive (PHP zip extension) is not available on this server.');
        }
        $za = new ZipArchive();
        if ($za->open($tmpZip) !== true) {
            return $this->l('The downloaded package is not a valid zip file.');
        }
        $hasMain = false;
        for ($i = 0; $i < $za->numFiles; $i++) {
            $name = $za->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            if (strpos($name, 'scmorderuntil/') !== 0 || strpos($name, '..') !== false) {
                $za->close();
                return $this->l('The package contains unexpected paths and was rejected.');
            }
            if ($name === 'scmorderuntil/scmorderuntil.php') {
                $hasMain = true;
            }
        }
        if (!$hasMain) {
            $za->close();
            return $this->l('The package does not look like the scmorderuntil module.');
        }
        $ok = $za->extractTo(_PS_MODULE_DIR_);
        $za->close();
        if (!$ok) {
            return $this->l('Extraction failed — check write permissions on the modules/ directory.');
        }
        return true;
    }

    /** Best-effort cache clear so PrestaShop reloads the replaced files. */
    private function cleanCaches()
    {
        if (method_exists('Tools', 'clearAllCache')) {
            try {
                Tools::clearAllCache();
            } catch (Exception $e) {
                // noop — cache clearing is best-effort.
            }
        }
        foreach ((array) glob(_PS_CACHE_DIR_ . 'scmorderuntil_*.json') as $f) {
            @unlink($f);
        }
    }

    /** The "Module updates" panel shown above the settings form. */
    private function renderUpdatePanel($latest)
    {
        $action = AdminController::$currentIndex . '&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules');
        $esc = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };

        $html = '<div class="panel">'
            . '<div class="panel-heading"><i class="icon-cloud-download"></i> '
            . $this->l('Module updates') . '</div>'
            . '<form method="post" action="' . $esc($action) . '">'
            . '<p>' . sprintf($this->l('Installed version: %s'), '<strong>' . $esc($this->version) . '</strong>')
            . '</p>'
            . '<p class="help-block">'
            . sprintf($this->l('Updates are pulled from %s.'), '<code>' . $esc($this->updateRepo()) . '</code>')
            . '</p>';

        if (is_array($latest)) {
            if (version_compare($latest['version'], $this->version, '>')) {
                $html .= '<div class="alert alert-info">'
                    . sprintf($this->l('A new version is available: %s.'), '<strong>' . $esc($latest['tag']) . '</strong>');
                if ($latest['html'] !== '') {
                    $html .= ' <a href="' . $esc($latest['html']) . '" target="_blank" rel="noopener noreferrer">'
                        . $this->l('Release notes') . '</a>';
                }
                $html .= '</div>'
                    . '<button type="submit" name="submitScmouUpdate" class="btn btn-primary">'
                    . '<i class="icon-download"></i> '
                    . sprintf($this->l('Download & install %s'), $esc($latest['tag']))
                    . '</button> ';
            } else {
                $html .= '<div class="alert alert-success">'
                    . $this->l('You are running the latest version.') . '</div>';
            }
        }

        $html .= '<button type="submit" name="submitScmouCheck" class="btn btn-default">'
            . '<i class="icon-refresh"></i> ' . $this->l('Check for updates') . '</button>'
            . '</form></div>';

        return $html;
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

        // Self-update from the GitHub release package.
        $latest = null;
        if (Tools::isSubmit('submitScmouCheck')) {
            $latest = $this->fetchLatestRelease();
            if ($latest === null) {
                $output .= $this->displayError(
                    $this->l('Could not reach GitHub to check for updates.')
                );
            }
        }
        if (Tools::isSubmit('submitScmouUpdate')) {
            $res = $this->performSelfUpdate();
            if (!empty($res['ok'])) {
                // Files are replaced; reload so PrestaShop runs the upgrade
                // scripts and picks up the new class/templates.
                Tools::redirectAdmin(
                    AdminController::$currentIndex . '&configure=' . $this->name
                    . '&token=' . Tools::getAdminTokenLite('AdminModules')
                    . '&scmou_updated=' . urlencode($res['version'])
                );
            }
            $output .= $this->displayError($res['message']);
        }
        $justUpdated = Tools::getValue('scmou_updated');
        if ($justUpdated !== false && $justUpdated !== '') {
            $output .= $this->displayConfirmation(sprintf(
                $this->l('Module updated to %s.'),
                htmlspecialchars((string) $justUpdated, ENT_QUOTES, 'UTF-8')
            ));
        }

        return $output . $this->renderUpdatePanel($latest) . $this->renderConfigForm();
    }

    private function postProcessConfig()
    {
        $types = [
            'API_URL' => 'string', 'API_KEY' => 'string', 'CALL_MODE' => 'string',
            'PLACEMENT' => 'string', 'CUTOFF' => 'string', 'DELIVERY_OFFSET' => 'int',
            'LOCALE' => 'string', 'CACHE_TTL' => 'int', 'TIMEOUT' => 'int',
            'SHOW_PRODUCT' => 'bool', 'SHOW_CART' => 'bool',
            'REFRESH' => 'bool', 'UPDATE_REPO' => 'string', 'UPDATE_TOKEN' => 'string',
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
                        'desc' => $this->l('Placeholders: {cutoff} {shipwhen} {when} {delivery}. Empty = built-in default.'),
                    ],
                    [
                        'type' => 'text', 'lang' => true,
                        'label' => $this->l('Text — window closed'),
                        'name' => self::PREFIX . 'TPL_CLOSED',
                        'desc' => $this->l('Placeholders: {cutoff} {shipwhen} {when} {delivery}. Empty = built-in default.'),
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
                    [
                        'type' => 'text', 'label' => $this->l('Update source (GitHub owner/repo)'),
                        'name' => self::PREFIX . 'UPDATE_REPO',
                        'desc' => $this->l('Repository the "Check for updates" button reads releases from.'),
                    ],
                    [
                        'type' => 'text', 'label' => $this->l('GitHub token (optional)'),
                        'name' => self::PREFIX . 'UPDATE_TOKEN',
                        'desc' => $this->l('Only for a private repo or to avoid GitHub API rate limits.'),
                    ],
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
