<?php

/**
 * WordPress, WooCommerce and WCPOS Pro stubs for the plain-PHP regression tests.
 *
 * wcpos_pro_requires() and wcpos_pro_register_server_provider() are deliberately NOT defined here:
 * bootstrap-gate.php checks the plugin stays inert without them.
 */

namespace WCPOS\WooCommercePOSPro\Payments\Server
{
    // Copies of Pro origin/main's signatures (includes/Payments/Server/*.php).
    interface Provider_Adapter_Interface
    {
        public function provider(): string;

        public function describe(\WC_Payment_Gateway $gateway): array;

        public function list_readers();

        public function create_reader_action(array $row, string $reader_id);

        public function fetch(string $ref);

        public function cancel(string $ref);

        public function capture(string $ref);

        public function refund(array $row, int $refund_id, string $amount);

        public function verify_webhook(\WP_REST_Request $request);
    }

    abstract class Abstract_Provider_Adapter implements Provider_Adapter_Interface
    {
        public function diagnostics(\WC_Payment_Gateway $gateway): array
        {
            return array();
        }

        public function describe(\WC_Payment_Gateway $gateway): array
        {
            return array();
        }

        public function answer(string $ref, string $prompt_id, string $button_id)
        {
            return $this->unsupported();
        }

        public function capture(string $ref)
        {
            return $this->unsupported();
        }

        public function refund(array $row, int $refund_id, string $amount)
        {
            return $this->unsupported();
        }

        public function fetch_refund(array $row, array $allocation)
        {
            return $this->unsupported();
        }

        public function verify_webhook(\WP_REST_Request $request)
        {
            return $this->unsupported();
        }

        protected function indeterminate(string $code, string $message): \WP_Error
        {
            return new \WP_Error($code, $message, array('indeterminate' => true, 'status' => 502));
        }

        private function unsupported(): \WP_Error
        {
            return new \WP_Error('wcpos_capture_mode_unsupported', 'This payment provider does not support that operation.', array('status' => 501));
        }
    }

    // The real Money_Units logic, copied from Pro origin/main.
    final class Money_Units
    {
        private const EXPONENTS = array(
            'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
            'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
            'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        );

        public static function minor(string $amount, string $currency): int
        {
            $decimals = self::EXPONENTS[strtoupper($currency)] ?? 2;
            $negative = '-' === substr($amount, 0, 1);
            $parts = explode('.', ltrim($amount, '+-'), 2);
            $fraction = str_pad($parts[1] ?? '', $decimals + 1, '0');
            $minor = (int) $parts[0] * (10 ** $decimals) + (int) substr($fraction, 0, $decimals);
            $minor += (int) $fraction[$decimals] >= 5 ? 1 : 0;

            return $negative ? -$minor : $minor;
        }

        public static function major(int $minor, string $currency): string
        {
            $decimals = self::EXPONENTS[strtoupper($currency)] ?? 2;
            $digits = str_pad(ltrim((string) $minor, '-'), $decimals + 1, '0', STR_PAD_LEFT);
            $major = $decimals ? substr($digits, 0, -$decimals) . '.' . substr($digits, -$decimals) : $digits;

            return ($minor < 0 ? '-' : '') . $major;
        }
    }
}

namespace
{
    define('ABSPATH', __DIR__ . '/');

    require_once __DIR__ . '/Fake_Http.php';
    require_once __DIR__ . '/Fake_Wpdb.php';

    class WP_Error
    {
        /** @var string */
        private $code;
        /** @var string */
        private $message;
        /** @var mixed */
        private $data;

        /**
         * @param mixed $data
         */
        public function __construct(string $code = '', string $message = '', $data = '')
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        /**
         * @return mixed
         */
        public function get_error_data()
        {
            return $this->data;
        }
    }

    class WC_Payment_Gateway
    {
        /** @var string */
        public $id = '';
        /** @var array<string, mixed> */
        public $form_fields = array();
        /** @var array<string, mixed> */
        public $settings = array();
    }

    final class Antwc_Test_Logger
    {
        public function info($message, $context = array()): void
        {
            $this->record('info', $message, $context);
        }

        public function warning($message, $context = array()): void
        {
            $this->record('warning', $message, $context);
        }

        public function error($message, $context = array()): void
        {
            $this->record('error', $message, $context);
        }

        public function debug($message, $context = array()): void
        {
            $this->record('debug', $message, $context);
        }

        private function record(string $level, $message, $context): void
        {
            $GLOBALS['antwc_test_log'][] = array('level' => $level, 'message' => $message, 'context' => $context);
        }
    }

    $GLOBALS['antwc_test_options'] = array();
    $GLOBALS['antwc_test_transients'] = array();
    $GLOBALS['antwc_test_hooks'] = array();
    $GLOBALS['antwc_test_log'] = array();
    $GLOBALS['antwc_test_orders'] = array(); // order_id => transaction_id

    function get_option($name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['antwc_test_options']) ? $GLOBALS['antwc_test_options'][$name] : $default;
    }

    function add_option($name, $value = '', $deprecated = '', $autoload = 'yes')
    {
        if (array_key_exists($name, $GLOBALS['antwc_test_options'])) {
            return false;
        }
        $GLOBALS['antwc_test_options'][$name] = $value;

        return true;
    }

    function update_option($name, $value, $autoload = null)
    {
        $GLOBALS['antwc_test_options'][$name] = $value;

        return true;
    }

    function get_transient($name)
    {
        return $GLOBALS['antwc_test_transients'][$name] ?? false;
    }

    function set_transient($name, $value, $ttl = 0)
    {
        $GLOBALS['antwc_test_transients'][$name] = $value;

        return true;
    }

    function wp_json_encode($value)
    {
        return json_encode($value);
    }

    function wp_remote_post($url, $args = array())
    {
        return Antwc_Fake_Http::post($url, $args);
    }

    function wp_remote_retrieve_response_code($response)
    {
        return is_array($response) ? $response['response']['code'] : '';
    }

    function wp_remote_retrieve_body($response)
    {
        return is_array($response) ? $response['body'] : '';
    }

    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }

    function wc_get_orders(array $args)
    {
        $ids = array();
        foreach ($GLOBALS['antwc_test_orders'] as $order_id => $trans_id) {
            if ((string) $trans_id === (string) $args['transaction_id'] && !in_array($order_id, $args['exclude'] ?? array(), true)) {
                $ids[] = $order_id;
            }
        }

        return array_slice($ids, 0, (int) ($args['limit'] ?? 10));
    }

    function wc_get_logger()
    {
        return new Antwc_Test_Logger();
    }

    function wp_date($format, $timestamp = null)
    {
        return gmdate($format, $timestamp === null ? time() : (int) $timestamp);
    }

    function __($text, $domain = 'default')
    {
        return $text;
    }

    function esc_html__($text, $domain = 'default')
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }

    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }

    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }

    function apply_filters($hook, $value)
    {
        return $value;
    }

    function add_action($hook, $callback, $priority = 10, $args = 1)
    {
        $GLOBALS['antwc_test_hooks'][$hook][] = $callback;

        return true;
    }

    function add_filter($hook, $callback, $priority = 10, $args = 1)
    {
        return add_action($hook, $callback, $priority, $args);
    }

    function plugin_dir_path($file)
    {
        return dirname($file) . '/';
    }

    function plugin_dir_url($file)
    {
        return 'https://example.test/wp-content/plugins/' . basename(dirname($file)) . '/';
    }

    function register_activation_hook($file, $callback)
    {
        $GLOBALS['antwc_test_hooks']['activate'][] = $callback;
    }

    require_once dirname(__DIR__, 2) . '/authorize-net-terminal-for-woocommerce.php';

    use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Server\Authorize_Net_Server_Provider;
    use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Settings;

    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    function antwc_assert_same($expected, $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($label . "\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true));
        }
        echo '  ok - ' . $label . "\n";
    }

    /** The test clock: 2026-11-11 10:00:00 UTC. */
    const ANTWC_TEST_NOW = 1794391200;

    /**
     * Fresh options, transients, HTTP and database; sandbox settings with test credentials.
     *
     * @param array<string, string> $settings
     */
    function antwc_test_reset(array $settings = array()): void
    {
        $GLOBALS['antwc_test_options'] = array(
            'woocommerce_' . Settings::GATEWAY_ID . '_settings' => array_merge(
                array('environment' => 'sandbox', 'api_login_id' => 'test-login', 'transaction_key' => 'test-key', 'auto_match' => 'yes', 'reader_label' => 'Front till'),
                $settings
            ),
        );
        $GLOBALS['antwc_test_transients'] = array();
        $GLOBALS['antwc_test_log'] = array();
        $GLOBALS['antwc_test_orders'] = array();
        $GLOBALS['wpdb'] = new Antwc_Fake_Wpdb();
        Antwc_Fake_Http::reset();
    }

    /** Drop the 3 s unsettled-list cache, as if the next poll came later. */
    function antwc_test_expire_list(): void
    {
        foreach (array_keys($GLOBALS['antwc_test_transients']) as $key) {
            if (strpos($key, 'antwc_unsettled_') === 0) {
                unset($GLOBALS['antwc_test_transients'][$key]);
            }
        }
    }

    function antwc_test_provider(int $now = ANTWC_TEST_NOW): Authorize_Net_Server_Provider
    {
        return new Authorize_Net_Server_Provider(null, null, null, static function () use ($now) {
            return $now;
        });
    }

    /**
     * @return array<string, mixed>
     */
    function antwc_test_row(string $id = 'row-a', string $amount = '12.34', int $order_id = 101, string $currency = 'USD'): array
    {
        return array('id' => $id, 'order_id' => $order_id, 'amount' => $amount, 'currency' => $currency);
    }

    function antwc_test_time(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s.v', $timestamp) . 'Z';
    }

    /**
     * Add one approved tap to the fake account: in the unsettled list and in the details.
     *
     * @param array<string, mixed> $overrides Details fields to change (not the list item).
     */
    function antwc_test_tap(string $trans_id, float $amount, int $submitted, array $overrides = array()): void
    {
        Antwc_Fake_Http::$unsettled[] = array(
            'transId' => $trans_id,
            'submitTimeUTC' => antwc_test_time($submitted),
            'transactionStatus' => 'capturedPendingSettlement',
            'accountType' => 'Visa',
            'accountNumber' => 'XXXX1111',
            'settleAmount' => $amount,
        );
        Antwc_Fake_Http::$details[$trans_id] = array_merge(antwc_test_details($trans_id, $amount, $submitted), $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    function antwc_test_details(string $trans_id, float $amount, int $submitted): array
    {
        return array(
            'transId' => $trans_id,
            'transactionType' => 'authCaptureTransaction',
            'transactionStatus' => 'capturedPendingSettlement',
            'responseCode' => 1,
            'authAmount' => $amount,
            'settleAmount' => $amount,
            'submitTimeUTC' => antwc_test_time($submitted),
            'authCode' => 'ABC123',
            'payment' => array('creditCard' => array('cardNumber' => 'XXXX1111', 'cardType' => 'Visa')),
        );
    }

    /**
     * @param mixed $result
     */
    function antwc_test_code($result): string
    {
        return $result instanceof WP_Error ? $result->get_error_code() : 'not an error';
    }
}
