<?php
/**
 * Upgrade to 1.3.4.
 *
 * Adds the "Only on available products" option (SCMOU_SHOW_ONLY_AVAILABLE): the
 * product-page box is hidden on out-of-stock products (unless backorders are
 * allowed) so the delivery promise is only shown when the product can actually be
 * ordered. Seed it ON for existing installs so the behaviour is opt-out, and so
 * the Back Office switch does not render as off (which a save would then persist).
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
function upgrade_module_1_3_4($module)
{
    // Only seed if the key was never set, so we never overwrite a merchant choice.
    if (Configuration::get('SCMOU_SHOW_ONLY_AVAILABLE') === false) {
        Configuration::updateValue('SCMOU_SHOW_ONLY_AVAILABLE', 1);
    }

    return true;
}
