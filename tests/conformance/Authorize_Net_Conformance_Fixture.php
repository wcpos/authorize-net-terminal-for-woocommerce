<?php

namespace WCPOS\WooCommercePOS\AuthorizeNetTerminal\Tests\Conformance;

use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Gateway;
use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Services\AuthorizeNetClient;
use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Settings;
use WCPOS\WooCommercePOSPro\Payments\Server\Reader_Curation;
use WCPOS\WooCommercePOSPro\Payments\Server\Server_Providers;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Conformance_Fixture;

require_once __DIR__ . '/Fake_Authorize_Net_Transport.php';
require_once __DIR__ . '/Recording_Authorize_Net_Provider.php';

/**
 * Capabilities claimed beyond the floor, and why:
 * - `cancel`, `cancel_final`: before a transaction is claimed, cancel ends the leg at once (`final`);
 *   nothing was sent to Authorize.net, so there is nothing to wait for.
 * - `prompt`: when several transactions fit, the till waits on a pick-list and answer() claims one.
 * - `refund`, `partial_refund`: a void before settlement, refundTransaction after it.
 * - `test_live_isolation`: an action keeps the environment it was created in; after a switch it fails
 *   (`mode_changed`) without a request to either environment.
 * Not claimed: `webhook`, `webhook_money_only`, `legacy_adoption`, `historical_webview_refund` (no
 * webhooks); `manual_capture`; `cancel_requested_then_completed`, `cancel_unsupported` (cancel is
 * always final or refused once claimed); `expiry` (Authorize.net never ends a leg: the app holds no
 * pending sale, so Free's deadline ends it with a cancel); `refund_synchronous` (a refund held for
 * review, responseCode 4, is `pending`); `non_idempotent_create` (create sends nothing and is keyed on
 * the row id, so a replay returns the same action).
 */
final class Authorize_Net_Conformance_Fixture implements Conformance_Fixture
{
    private const CAPABILITIES = array('cancel', 'cancel_final', 'prompt', 'refund', 'partial_refund', 'test_live_isolation');

    /** @var Fake_Authorize_Net_Transport */
    public $transport;
    private $registry_property;
    private $old_registry;
    private $old_gateways;
    private $old_options;
    private $old_currency;
    private $calls = array();
    private $aliases = array();
    private $counts = array();
    private $lose_creates = 0;
    private $flip_to_production = false;

    public function gateway_id(): string
    {
        return Settings::GATEWAY_ID;
    }

    public function install(): void
    {
        $this->old_options = get_option('woocommerce_' . Settings::GATEWAY_ID . '_settings', array());
        $this->old_currency = get_option('woocommerce_currency');
        update_option('woocommerce_currency', 'USD');
        $this->set_environment('sandbox');
        foreach (array(Settings::SANDBOX_ENDPOINT, Settings::PRODUCTION_ENDPOINT) as $endpoint) {
            delete_transient(AuthorizeNetClient::currency_cache_key($endpoint, Fake_Authorize_Net_Transport::LOGIN));
        }
        $this->transport = new Fake_Authorize_Net_Transport();
        Recording_Authorize_Net_Provider::$fixture = $this;
        add_filter('pre_http_request', array($this->transport, 'handle'), 10, 3);
        $this->registry_property = new \ReflectionProperty(Server_Providers::class, 'instance');
        $this->registry_property->setAccessible(true);
        $this->old_registry = $this->registry_property->getValue();
        $this->registry_property->setValue(null, null);
        wcpos_pro_register_server_provider(Settings::GATEWAY_ID, Recording_Authorize_Net_Provider::class);
        $this->old_gateways = WC()->payment_gateways;
        add_filter('woocommerce_payment_gateways', array($this, 'register_gateway'));
        WC()->payment_gateways = new \WC_Payment_Gateways();
        Reader_Curation::forget(Settings::GATEWAY_ID);
        delete_option('wcpos_pro_readers_lkg_' . Settings::GATEWAY_ID);
    }

    public function uninstall(): void
    {
        remove_filter('pre_http_request', array($this->transport, 'handle'), 10);
        remove_filter('woocommerce_payment_gateways', array($this, 'register_gateway'));
        Recording_Authorize_Net_Provider::$fixture = null;
        Reader_Curation::forget(Settings::GATEWAY_ID);
        delete_option('wcpos_pro_readers_lkg_' . Settings::GATEWAY_ID);
        $this->registry_property->setValue(null, $this->old_registry);
        WC()->payment_gateways = $this->old_gateways;
        update_option('woocommerce_' . Settings::GATEWAY_ID . '_settings', $this->old_options);
        update_option('woocommerce_currency', $this->old_currency);
        if (count($this->transport->unexpected) > 0) {
            throw new \LogicException('Unexpected Authorize.net requests: ' . implode('; ', $this->transport->unexpected));
        }
    }

    /**
     * @param array<int, mixed> $gateways
     * @return array<int, mixed>
     */
    public function register_gateway(array $gateways): array
    {
        $gateways[] = Gateway::class;

        return $gateways;
    }

    public function currency(): string
    {
        return 'USD';
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES, true);
    }

    public function script(string $scenario): void
    {
        // Scenario => app taps newly listed at each fetch ("amount" or "amount:status"), the refund's
        // responseCode, the merchant account's currencies. The order total is 92.95.
        $scripts = array(
            'create_ok' => array(array()),
            'create_indeterminate' => array(array()),
            'pending_then_completed' => array(array(), array('92.95')),
            'declined' => array(array('92.95:declined')),
            'cancel_requested_then_cancelled' => array(array()),
            'cancel_final' => array(array()),
            'amount_mismatch' => array(array('90.00')),
            'currency_mismatch' => array(array('92.95')), // The merchant account settles only EUR (below).
            'expired' => array(array()),
            'prompt' => array(array('92.95', '92.95')),
            'refund_ok' => array(array('92.95')),
            'refund_pending' => array(array('92.95')),
            'refund_failed' => array(array('92.95')),
            'test_live_isolation' => array(array('92.95')),
        );
        if (!isset($scripts[$scenario]) || !is_array($scripts[$scenario])) {
            throw new \OutOfBoundsException('Unknown conformance scenario: ' . $scenario);
        }
        $codes = array('refund_pending' => '4', 'refund_failed' => '2');
        $this->transport->script($scripts[$scenario], $codes[$scenario] ?? '1', $scenario === 'currency_mismatch' ? array('EUR') : array('USD'));
        $this->lose_creates = $scenario === 'create_indeterminate' ? 1 : 0;
        $this->flip_to_production = $scenario === 'test_live_isolation';
    }

    public function webhook_request(string $event): \WP_REST_Request
    {
        throw new \OutOfBoundsException('Authorize.net Tap to Pay has no webhooks: ' . $event);
    }

    /** True once per lost create the scenario armed. */
    public function lose_create(): bool
    {
        if ($this->lose_creates <= 0) {
            return false;
        }
        --$this->lose_creates;

        return true;
    }

    /** Before each adapter fetch: the app's next taps are listed; test_live_isolation switches to production first. */
    public function before_fetch(): void
    {
        if ($this->flip_to_production) {
            $this->flip_to_production = false;
            $this->set_environment('production');
        }
        $this->transport->advance();
        // The adapter shares the list for 3 s between tills; each lesson poll stands for a later poll.
        delete_transient('antwc_unsettled_' . md5(Settings::endpoint() . '|' . Fake_Authorize_Net_Transport::LOGIN));
    }

    /** The environment the adapter stored for a row's action, or null when it stored none. */
    public function action_mode(string $row_id): ?string
    {
        $raw = $row_id === '' ? null : get_option('antwc_action_' . $row_id, null);
        $record = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($record) && isset($record['mode']) ? (string) $record['mode'] : null;
    }

    /** One stable alias per id and kind (action_N, txn_N); no id is `none`. */
    public function alias(string $ref, string $kind = 'action'): string
    {
        if ($ref === '') {
            return 'none';
        }
        if (!isset($this->aliases[$kind][$ref])) {
            $this->counts[$kind] = ($this->counts[$kind] ?? 0) + 1;
            $this->aliases[$kind][$ref] = $kind . '_' . $this->counts[$kind];
        }

        return $this->aliases[$kind][$ref];
    }

    /** Record an adapter operation on entry; returns its index for complete(). */
    public function record(string $op, string $ref, string $details): int
    {
        $this->calls[] = array('op' => $op, 'request' => trim('action=' . $this->alias($ref) . ' ' . $details));

        return count($this->calls) - 1;
    }

    public function complete(int $entry, string $details): void
    {
        $this->calls[$entry]['request'] .= ' ' . $details;
    }

    public function transcript(): array
    {
        return $this->calls;
    }

    public function reset_transcript(): void
    {
        $this->calls = array();
        $this->aliases = array();
        $this->counts = array();
    }

    public function transcript_dir(): ?string
    {
        return __DIR__ . '/transcripts';
    }

    private function set_environment(string $environment): void
    {
        update_option('woocommerce_' . Settings::GATEWAY_ID . '_settings', array(
            'title' => 'Authorize.net Tap to Pay',
            'environment' => $environment,
            'api_login_id' => Fake_Authorize_Net_Transport::LOGIN,
            'transaction_key' => Fake_Authorize_Net_Transport::KEY,
            'auto_match' => 'yes',
            'reader_label' => 'Authorize.net app',
        ));
    }
}
