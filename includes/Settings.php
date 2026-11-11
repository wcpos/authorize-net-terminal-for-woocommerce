<?php

namespace WCPOS\WooCommercePOS\AuthorizeNetTerminal;

/**
 * Read-only view of the gateway's WooCommerce settings option, the only place credentials live.
 */
final class Settings
{
    public const GATEWAY_ID = 'authorize_net_terminal_for_woocommerce';

    /** Authorize.net's JSON API endpoints (Authorize.net API reference, "Sandbox" and "Production"). */
    public const SANDBOX_ENDPOINT = 'https://apitest.authorize.net/xml/v1/request.api';
    public const PRODUCTION_ENDPOINT = 'https://api.authorize.net/xml/v1/request.api';

    public const DEFAULT_READER_LABEL = 'Authorize.net app';

    /** @var bool|null */
    private static $enabled_for_pos;

    /**
     * 'production' only when chosen explicitly; anything else is the sandbox.
     */
    public static function environment(): string
    {
        return self::get('environment', 'sandbox') === 'production' ? 'production' : 'sandbox';
    }

    public static function endpoint(): string
    {
        return self::environment() === 'production' ? self::PRODUCTION_ENDPOINT : self::SANDBOX_ENDPOINT;
    }

    public static function api_login_id(): string
    {
        return self::get('api_login_id', '');
    }

    public static function transaction_key(): string
    {
        return self::get('transaction_key', '');
    }

    public static function auto_match(): bool
    {
        return self::get('auto_match', 'yes') === 'yes';
    }

    public static function reader_label(): string
    {
        $label = self::get('reader_label', self::DEFAULT_READER_LABEL);

        return $label === '' ? self::DEFAULT_READER_LABEL : $label;
    }

    public static function configured(): bool
    {
        return self::api_login_id() !== '' && self::transaction_key() !== '';
    }

    /**
     * Whether POS → Settings → Checkout has the gateway switched on.
     */
    public static function enabled_for_pos(): bool
    {
        if (self::$enabled_for_pos === null) {
            $getter = function_exists('wcpos_get_settings') ? 'wcpos_get_settings' : 'woocommerce_pos_get_settings';
            $settings = function_exists($getter) ? $getter('payment_gateways') : array();
            self::$enabled_for_pos = is_array($settings) && !empty($settings['gateways'][self::GATEWAY_ID]['enabled']);
        }

        return self::$enabled_for_pos;
    }

    /** All but the last four characters hidden. */
    public static function mask(string $value): string
    {
        return strlen($value) <= 4 ? str_repeat('•', strlen($value)) : '••••' . substr($value, -4);
    }

    private static function get(string $key, string $default): string
    {
        $settings = function_exists('get_option') ? get_option('woocommerce_' . self::GATEWAY_ID . '_settings', array()) : array();
        if (!is_array($settings) || !isset($settings[$key]) || !is_scalar($settings[$key])) {
            return $default;
        }

        return trim((string) $settings[$key]);
    }
}
