<?php

namespace WCPOS\WooCommercePOS\AuthorizeNetTerminal;

/**
 * POS-only gateway. The card is taken in Authorize.net's own phone app; WCPOS Pro's server adapter
 * finds that transaction and Free records it, so this class only holds settings and routes refunds.
 */
class Gateway extends \WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = Settings::GATEWAY_ID;
        $this->method_title = 'Authorize.net Tap to Pay';
        $this->method_description = 'Take the card in the Authorize.net phone app; WooCommerce POS finds and records the payment.';
        $this->has_fields = false;
        // Refunds of a payment WCPOS Pro's ledger holds go through Pro to the Authorize.net API.
        $this->supports = array('products', 'refunds');

        if (function_exists('add_action')) {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        $this->init_form_fields();
        $this->init_settings();

        $this->title = (string) $this->get_option('title', 'Authorize.net Tap to Pay');
        $this->description = (string) $this->get_option('description', '');
    }

    /**
     * POS-only: available on a POS request, and on the order-pay page to a user who may run the POS when
     * the POS has the gateway switched on; never on the shop's checkout.
     *
     * @return bool
     */
    public function is_available()
    {
        if (!Settings::configured()) {
            return false;
        }
        if (function_exists('woocommerce_pos_request') && woocommerce_pos_request()) {
            return true;
        }

        return function_exists('is_checkout_pay_page') && is_checkout_pay_page()
            && function_exists('current_user_can') && current_user_can('access_woocommerce_pos')
            && Settings::enabled_for_pos();
    }

    public function init_form_fields(): void
    {
        // No "enabled" checkbox: the gateway is POS-only (see is_available()); POS → Settings → Checkout switches it on.
        $this->form_fields = array(
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'default' => 'Authorize.net Tap to Pay',
                'desc_tip' => true,
                'description' => 'Payment method title shown in the POS.',
            ),
            'environment' => array(
                'title' => 'Environment',
                'type' => 'select',
                'default' => 'sandbox',
                'options' => array(
                    'sandbox' => 'Sandbox',
                    'production' => 'Production',
                ),
                'description' => 'Sandbox uses apitest.authorize.net and a sandbox account. Production takes real money.',
            ),
            'api_login_id' => array(
                'title' => 'API Login ID',
                'type' => 'antwc_secret',
                'input_type' => 'text',
                'default' => '',
                'description' => 'From the Authorize.net Merchant Interface: Account → Settings → API Credentials & Keys.',
            ),
            'transaction_key' => array(
                'title' => 'Transaction Key',
                'type' => 'antwc_secret',
                'input_type' => 'password',
                'default' => '',
                'description' => 'Stored on the server only and never shown again.',
            ),
            'auto_match' => array(
                'title' => 'Auto-match',
                'type' => 'checkbox',
                'label' => 'Complete the payment by itself when exactly one Authorize.net transaction fits',
                'default' => 'yes',
            ),
            'reader_label' => array(
                'title' => 'Reader name',
                'type' => 'text',
                'default' => Settings::DEFAULT_READER_LABEL,
                'description' => 'The name the POS shows for the phone app.',
            ),
        );
    }

    /**
     * Render a credential without its saved value: a masked placeholder and "leave blank to keep it".
     *
     * @param string $key
     * @param array<string, mixed> $data
     */
    public function generate_antwc_secret_html($key, $data): string
    {
        $fieldKey = $this->get_field_key($key);
        $saved = trim((string) $this->get_option($key, ''));
        $placeholder = $saved !== '' ? Settings::mask($saved) : 'Enter value';
        $status = $saved !== '' ? 'Saved value: ' . Settings::mask($saved) . '. Leave blank to keep it.' : 'No saved value.';
        $type = isset($data['input_type']) && $data['input_type'] === 'text' ? 'text' : 'password';

        return '<tr valign="top"><th scope="row" class="titledesc"><label for="' . esc_attr($fieldKey) . '">' . esc_html((string) ($data['title'] ?? $key)) . '</label></th>'
            . '<td class="forminp"><input class="input-text regular-input" type="' . $type . '" autocomplete="off" id="' . esc_attr($fieldKey) . '" name="' . esc_attr($fieldKey) . '" value="" placeholder="' . esc_attr($placeholder) . '" />'
            . '<p class="description">' . esc_html($status . ' ' . (string) ($data['description'] ?? '')) . '</p></td></tr>';
    }

    /**
     * A blank submission keeps the saved credential; control characters are refused.
     *
     * @param string $key
     * @param mixed $value
     */
    public function validate_antwc_secret_field($key, $value): string
    {
        $existing = trim((string) $this->get_option($key, ''));
        if (!is_scalar($value) || trim((string) $value) === '') {
            return $existing;
        }
        if (preg_match('/[[:cntrl:]]/', (string) $value) === 1) {
            if (class_exists('WC_Admin_Settings')) {
                \WC_Admin_Settings::add_error('Authorize.net credentials cannot contain control characters.');
            }

            return $existing;
        }

        return trim((string) $value);
    }

    /**
     * @return array<string, string>
     */
    public function process_payment($order_id): array
    {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (is_object($order) && function_exists('wcpos_pro_order_pay_process')) {
            // Pro's panel drives the leg and reads the ledger; it answers the form submit.
            return wcpos_pro_order_pay_process($order);
        }
        if (function_exists('wc_add_notice')) {
            wc_add_notice('Authorize.net Tap to Pay is only available in WooCommerce POS.', 'error');
        }

        return array('result' => 'failure');
    }

    /**
     * @param int $order_id
     * @param float|null $amount
     * @param string $reason
     * @return bool|\WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!is_object($order)) {
            return new \WP_Error('antwc_invalid_order', 'Invalid order.');
        }
        if (function_exists('wcpos_pro_order_pay_refund')) {
            return wcpos_pro_order_pay_refund($order, $amount, (string) $reason);
        }

        return new \WP_Error('antwc_refund_needs_pro', 'Refund this payment in the Authorize.net Merchant Interface.');
    }
}
