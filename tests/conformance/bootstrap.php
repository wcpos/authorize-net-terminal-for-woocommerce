<?php
/**
 * Conformance bootstrap: WordPress, WooCommerce, Free and Pro from the Pro checkout (WCPOS_PRO_DIR, else
 * the sibling plugin directory), then this extension, then Pro's provider conformance case. Runs under
 * wp-env (see .wp-env.json); the plain regression scripts in tests/regression are unaffected.
 */

$tests_dir = getenv('WP_TESTS_DIR') ?: getenv('WP_PHPUNIT__DIR') ?: '/tmp/wordpress-tests-lib';
require_once $tests_dir . '/includes/functions.php';
tests_add_filter('muplugins_loaded', static function () {
    require dirname(__DIR__, 2) . '/authorize-net-terminal-for-woocommerce.php';
}, 11);
$pro_dir = getenv('WCPOS_PRO_DIR') ?: dirname(__DIR__, 2) . '/../woocommerce-pos-pro';
require $pro_dir . '/tests/bootstrap.php';
require_once $pro_dir . '/tests/includes/Conformance/Conformance_Fixture.php';
require_once $pro_dir . '/tests/includes/Conformance/Provider_Conformance_Test_Case.php';
