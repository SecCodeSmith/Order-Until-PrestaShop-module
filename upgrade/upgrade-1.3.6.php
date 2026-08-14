<?php
/**
 * Upgrade to 1.3.6.
 *
 * Moves the cart-page countdown UP into the order-summary card, next to the
 * "Proceed to checkout" button, by registering displayExpressCheckout (where
 * express-checkout buttons render). displayReassurance stays registered as a
 * fallback for themes that do not emit displayExpressCheckout; the module's
 * static render-once guard makes displayExpressCheckout win when both fire.
 *
 * @author  SCM Order Until
 * @license MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Scmorderuntil $module
 *
 * @return bool
 */
function upgrade_module_1_3_6($module)
{
    // registerHook is idempotent, so re-running the upgrade is harmless.
    return (bool) $module->registerHook('displayExpressCheckout');
}
