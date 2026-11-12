<?php

namespace WCPOS\WooCommercePOS\AuthorizeNetTerminal\Server;

use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Settings;

/**
 * Registers the server adapter with WCPOS Pro. Called from antwc_init() (plugins_loaded at 30), which has
 * already asked wcpos_pro_requires() for REQUIRED_PRO_VERSION.
 */
final class Registration
{
    /** First Pro release the plugin runs on: the shared payments base. */
    public const REQUIRED_PRO_VERSION = '2.0.0';

    public static function register(): bool
    {
        if (!function_exists('wcpos_pro_register_server_provider')) {
            return false;
        }

        wcpos_pro_register_server_provider(Settings::GATEWAY_ID, Authorize_Net_Server_Provider::class);

        return true;
    }
}
