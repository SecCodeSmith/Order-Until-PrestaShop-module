<?php
/**
 * Upgrade to 1.3.0.
 *
 * The delivery countdown used to render via displayProductPriceBlock (which also
 * fires inside product-list miniatures, the add-to-cart modal and cart line
 * items) and via displayShoppingCartFooter (below the cart products). This moves
 * the cart placement into the order-summary column, next to the "Proceed to
 * checkout" button:
 *   - drop the old displayShoppingCartFooter hook,
 *   - register displayReassurance (the module gates it to the cart controller and
 *     to a single render at runtime).
 *
 * The product-page placement (displayProductPriceBlock / displayProductAdditional-
 * Info) is unchanged here; it is now gated to the product controller in code.
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
function upgrade_module_1_3_0($module)
{
    // Safe even if the hook was never registered.
    $module->unregisterHook('displayShoppingCartFooter');

    // registerHook is idempotent, so re-running the upgrade is harmless.
    return (bool) $module->registerHook('displayReassurance');
}
