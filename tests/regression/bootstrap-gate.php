<?php

use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Server\Authorize_Net_Server_Provider;
use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Settings;

antwc_test_reset();
$GLOBALS['antwc_test_hooks'] = array();

antwc_assert_same(false, function_exists('wcpos_pro_requires'), 'bootstrap-gate: test runs without WCPOS Pro');
antwc_init();
antwc_assert_same(false, isset($GLOBALS['antwc_test_hooks']['woocommerce_payment_gateways']), 'bootstrap-gate: no Pro, no gateway filter');
antwc_assert_same(array('antwc_pro_requirement_notice'), $GLOBALS['antwc_test_hooks']['admin_notices'] ?? array(), 'bootstrap-gate: no Pro, only the admin notice');
antwc_assert_same(false, isset($GLOBALS['antwc_test_registered_providers']), 'bootstrap-gate: no Pro, no provider registered');

ob_start();
antwc_pro_requirement_notice();
antwc_assert_same(true, strpos((string) ob_get_clean(), 'WooCommerce POS Pro 2.0.0 or newer') !== false, 'bootstrap-gate: notice names Pro 2.0.0');

// A Pro that refuses this plugin's version requirement also leaves it inert.
$GLOBALS['antwc_test_pro_answer'] = false;
// Declared conditionally so they exist only from here on, not when the file is compiled.
if (!function_exists('wcpos_pro_requires')) {
    function wcpos_pro_requires($version, $file)
    {
        $GLOBALS['antwc_test_pro_required'] = $version;

        return $GLOBALS['antwc_test_pro_answer'];
    }

    function wcpos_pro_register_server_provider($gateway_id, $class)
    {
        $GLOBALS['antwc_test_registered_providers'][$gateway_id] = $class;

        return true;
    }
}

$GLOBALS['antwc_test_hooks'] = array();
antwc_init();
antwc_assert_same('2.0.0', $GLOBALS['antwc_test_pro_required'], 'bootstrap-gate: asks Pro for 2.0.0');
antwc_assert_same(false, isset($GLOBALS['antwc_test_hooks']['woocommerce_payment_gateways']) || isset($GLOBALS['antwc_test_registered_providers']), 'bootstrap-gate: Pro refusing leaves it inert');

$GLOBALS['antwc_test_pro_answer'] = true;
$GLOBALS['antwc_test_hooks'] = array();
antwc_init();
antwc_assert_same(array(Settings::GATEWAY_ID => Authorize_Net_Server_Provider::class), $GLOBALS['antwc_test_registered_providers'], 'bootstrap-gate: with Pro, the provider is registered');
$filter = $GLOBALS['antwc_test_hooks']['woocommerce_payment_gateways'][0];
antwc_assert_same(array('WCPOS\\WooCommercePOS\\AuthorizeNetTerminal\\Gateway'), $filter(array()), 'bootstrap-gate: with Pro, the gateway is added');
