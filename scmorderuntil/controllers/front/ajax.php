<?php
/**
 * AJAX front controller — server-side proxy to the FastAPI delivery estimate.
 *
 * The browser calls THIS controller (same origin), which then calls the FastAPI
 * service in PHP. This avoids CORS and keeps the internal API URL / key hidden.
 *
 * URL: index.php?fc=module&module=scmorderuntil&controller=ajax&action=estimate&ajax=1
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class ScmorderuntilAjaxModuleFrontController extends ModuleFrontController
{
    /** @var bool no template/checkout context needed */
    public $ajax = true;

    public function initContent()
    {
        parent::initContent();
    }

    /**
     * Called by the dispatcher when ajax=1. Returns the delivery estimate JSON.
     */
    public function displayAjax()
    {
        $this->respond();
    }

    /** Also handle the case where displayAjax is not auto-invoked. */
    public function postProcess()
    {
        if (Tools::getValue('action') === 'estimate') {
            $this->respond();
        }
    }

    private function respond()
    {
        $estimate = $this->module->getDeliveryEstimate();

        header('Content-Type: application/json; charset=utf-8');
        // Small client cache; the deadline is an absolute timestamp so this is safe.
        header('Cache-Control: public, max-age=60');

        if ($estimate === null) {
            http_response_code(502);
            echo json_encode(['error' => 'upstream_unavailable']);
        } else {
            echo json_encode($estimate);
        }
        exit;
    }
}
