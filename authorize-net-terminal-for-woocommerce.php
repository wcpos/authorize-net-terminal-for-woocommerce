<?php
/**
 * Plugin Name: Authorize.net Tap to Pay for WooCommerce POS
 * Description: Take card payments in the Authorize.net phone app and record them in WooCommerce POS.
 * Version: 0.1.0-beta1
 * Author: kilbot
 * License: GPL-3.0-or-later
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce, woocommerce-pos-pro
 * Text Domain: authorize-net-terminal-for-woocommerce
 * WC requires at least: 7.0
 * WC tested up to: 10.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ANTWC_VERSION', '0.1.0-beta1');
define('ANTWC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ANTWC_PLUGIN_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function ($class) {
    $prefix = 'WCPOS\\WooCommercePOS\\AuthorizeNetTerminal\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = ANTWC_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_readable($file)) {
        require $file;
    }
});

if (function_exists('register_activation_hook')) {
    register_activation_hook(__FILE__, static function () {
        if (version_compare(PHP_VERSION, '7.4', '>=')) {
            // Pro-only at 2.0; Pro records the requirement for its own notice.
            if (function_exists('wcpos_pro_requires')) {
                wcpos_pro_requires('2.0.0', __FILE__);
            }

            return;
        }

        if (function_exists('deactivate_plugins') && function_exists('plugin_basename')) {
            deactivate_plugins(plugin_basename(__FILE__));
        }

        if (function_exists('wp_die')) {
            wp_die(esc_html__('Authorize.net Tap to Pay for WooCommerce POS requires PHP 7.4 or higher.', 'authorize-net-terminal-for-woocommerce'));
        }
    });
}

if (function_exists('add_action')) {
    add_action('before_woocommerce_init', static function () {
        if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    });

    // WCPOS Pro defines wcpos_pro_requires() and its provider registration from its own
    // plugins_loaded hook at priority 20, so the gate runs at 30.
    add_action('plugins_loaded', 'antwc_init', 30);
}

/**
 * The notice shown when no compatible WCPOS Pro is active.
 */
function antwc_pro_requirement_notice(): void
{
    echo '<div class="notice notice-error"><p>' . esc_html__('Authorize.net Tap to Pay for WooCommerce POS needs WooCommerce POS Pro 2.0.0 or newer.', 'authorize-net-terminal-for-woocommerce') . '</p></div>';
}

/**
 * Register the gateway and the server adapter, or nothing but a notice without a compatible WCPOS Pro.
 */
function antwc_init(): void
{
    $registration = 'WCPOS\\WooCommercePOS\\AuthorizeNetTerminal\\Server\\Registration';

    if (!function_exists('wcpos_pro_requires') || !wcpos_pro_requires($registration::REQUIRED_PRO_VERSION, __FILE__)) {
        add_action('admin_notices', 'antwc_pro_requirement_notice');

        return;
    }

    add_filter('woocommerce_payment_gateways', static function ($gateways) {
        $gatewayClass = 'WCPOS\\WooCommercePOS\\AuthorizeNetTerminal\\Gateway';

        if (class_exists('WC_Payment_Gateway') && class_exists($gatewayClass) && is_subclass_of($gatewayClass, 'WC_Payment_Gateway')) {
            $gateways[] = $gatewayClass;
        }

        return $gateways;
    });

    $registration::register();
}
