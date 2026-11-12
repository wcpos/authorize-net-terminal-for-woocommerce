<?php

namespace WCPOS\WooCommercePOS\AuthorizeNetTerminal\Server;

use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Logger;
use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Services\AuthorizeNetClient;
use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Services\AuthorizeNetException;
use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Services\TransactionVerifier;
use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Settings;
use WCPOS\WooCommercePOSPro\Payments\Server\Abstract_Provider_Adapter;
use WCPOS\WooCommercePOSPro\Payments\Server\Money_Units;

/**
 * Authorize.net Tap to Pay behind WCPOS Pro's server contract; Free owns the ledger.
 *
 * Nothing is sent at create time: the cashier taps the card in Authorize.net's own phone app, and fetch()
 * finds that transaction in the unsettled list (Transaction Details API). A transaction completes a leg
 * only after it passes TransactionVerifier on its full details and Claim_Store claims it for this row,
 * in the same call, or when the claim is already stored. Every doubt stays in_progress or indeterminate.
 * Two outcomes end a leg without money: a same-amount decline while nothing captured fits (failed), and
 * a requested cancel that the next fetch finds no single fresh tap for (cancelled). A tap whose amount or
 * currency is wrong is reported as Authorize.net has it, unclaimed, so Free fails the row.
 */
class Authorize_Net_Server_Provider extends Abstract_Provider_Adapter
{
    public const PROVIDER = 'authorize_net';
    public const READER_ID = 'authorize-net-app';
    /** Candidates may be up to 15 minutes older than the leg: a tap made just before it, or a retry after an expired leg. */
    public const LOOKBACK_SECONDS = 900;
    /** Auto-match allows only clock skew (30 s) before the leg's start; an earlier tap needs a human pick. */
    public const AUTO_MATCH_SKEW_SECONDS = 30;
    /** Most buttons a pick-list shows. */
    public const MAX_CANDIDATES = 6;
    /** The unsettled list is shared for 3 s, so 2-second polls from several tills cost one API call. */
    private const LIST_CACHE_SECONDS = 3;
    /** Error code Authorize.net gives a transaction refused as a duplicate within the duplicate window. */
    private const DUPLICATE_ERROR = '11';

    /** @var callable */
    private $client_factory;
    /** @var Action_Store */
    private $actions;
    /** @var Claim_Store */
    private $claims;
    /** @var callable */
    private $clock;
    /** @var TransactionVerifier */
    private $verifier;

    public function __construct(?callable $client_factory = null, ?Action_Store $actions = null, ?Claim_Store $claims = null, ?callable $clock = null)
    {
        $this->client_factory = $client_factory !== null ? $client_factory : static function () {
            return new AuthorizeNetClient(Settings::endpoint(), Settings::api_login_id(), Settings::transaction_key());
        };
        $this->actions = $actions !== null ? $actions : new Action_Store();
        $this->claims = $claims !== null ? $claims : new Claim_Store();
        $this->clock = $clock !== null ? $clock : 'time';
        $this->verifier = new TransactionVerifier();
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function describe(\WC_Payment_Gateway $gateway): array
    {
        // The app takes exactly the amount the cashier keys in; no tip is added on the phone.
        return array(
            'capabilities' => array('tips' => 'none', 'refunds' => array('via' => 'provider', 'partial' => true), 'void' => true),
            'provider_data' => array('mode' => Settings::environment()),
        );
    }

    public function list_readers()
    {
        if (!Settings::configured()) {
            return new \WP_Error('antwc_not_configured', __('Enter the Authorize.net API Login ID and Transaction Key first.', 'authorize-net-terminal-for-woocommerce'), array('status' => 400));
        }

        // The phone app is the only "reader", and it is never offline from the server's point of view.
        return array(array('id' => self::READER_ID, 'label' => Settings::reader_label(), 'status' => 'online'));
    }

    public function create_reader_action(array $row, string $reader_id)
    {
        try {
            $this->actions->create($row, Settings::environment(), $this->now());
        } catch (\RuntimeException $e) {
            return new \WP_Error('antwc_action_conflict', Logger::redact_untrusted_text($e->getMessage()), array('status' => 409));
        }

        return array('ref' => 'antwc:' . $row['id'], 'expires_at' => null);
    }

    public function fetch(string $ref)
    {
        $action = $this->action_for($ref);
        if ($action === null) {
            return self::unknown_action();
        }
        $settled = $this->settled_state($action);
        if ($settled !== null) {
            return $settled;
        }

        try {
            $client = $this->client();
            $list = $this->unsettled($client, (int) $action['created_at'] - self::LOOKBACK_SECONDS);
            $candidates = $this->candidates($list, $action);
            $refusal = null;
            // A cancel-requested leg has no till left to answer a prompt, so it auto-matches whatever the setting.
            $requested = $action['cancel_requested_at'] !== null;

            // Crash recovery: this row claimed a transaction but did not store it.
            foreach ($candidates as $candidate) {
                if ($candidate['ours']) {
                    $result = $this->verify_and_claim($client, $action, $candidate['trans_id']);
                    if (isset($result['status'])) {
                        return $result;
                    }
                    $refusal = $result;
                }
            }
            $ours_refused = $refusal !== null;

            if ($refusal === null && ($requested || Settings::auto_match())) {
                $fresh = array_values(array_filter($candidates, static function (array $candidate) use ($action) {
                    return $candidate['submitted'] >= (int) $action['created_at'] - self::AUTO_MATCH_SKEW_SECONDS;
                }));
                if (count($fresh) === 1) {
                    $result = $this->verify_and_claim($client, $action, $fresh[0]['trans_id']);
                    if (isset($result['status'])) {
                        return $result; // Completion wins over the cancel.
                    }
                    $refusal = $result;
                }
            }

            // A doubt about a listed tap is not a no: leave the cancel undecided so a later fetch decides again.
            if ($requested && $ours_refused) {
                return $this->indeterminate('antwc_claim_unverified', __('This payment was recorded by this sale but could not be verified with Authorize.net. Try again.', 'authorize-net-terminal-for-woocommerce'));
            }
            if ($requested && $refusal !== null && in_array($refusal['reason'], array('not_found', 'in_future'), true)) {
                return $this->indeterminate('antwc_cancel_undecided', __('Authorize.net lists a payment for this sale that could not be checked yet. Try again.', 'authorize-net-terminal-for-woocommerce'));
            }
            if ($requested) {
                $this->actions->mark_cancelled((string) $action['row_id']);

                return self::cancelled_observation();
            }
            if (count($candidates) === 0 && self::declined_since($list, $action)) {
                return array('status' => 'failed', 'amount' => null, 'currency' => null, 'failure_reason' => 'declined');
            }

            return self::prompt_observation($action, $candidates, $refusal);
        } catch (AuthorizeNetException $e) {
            return $this->api_unavailable($e);
        } catch (\RuntimeException $e) {
            return $this->indeterminate('antwc_unavailable', Logger::redact_untrusted_text($e->getMessage()));
        }
    }

    public function answer(string $ref, string $prompt_id, string $button_id)
    {
        $action = $this->action_for($ref);
        if ($action === null) {
            return self::unknown_action();
        }
        if ($action['cancel_requested_at'] !== null) {
            return new \WP_Error('antwc_cancel_requested', __('This payment is being cancelled.', 'authorize-net-terminal-for-woocommerce'), array('status' => 409));
        }
        if (preg_match('/^txn:(\d{1,20})$/', $button_id, $match) !== 1) {
            return new \WP_Error('antwc_bad_button', __('That choice is not an Authorize.net payment.', 'authorize-net-terminal-for-woocommerce'), array('status' => 400));
        }
        $settled = $this->settled_state($action);
        if ($settled !== null) {
            return $settled;
        }

        try {
            $client = $this->client();
            $result = $this->verify_and_claim($client, $action, $match[1]);
            if (isset($result['status'])) {
                return $result;
            }

            // A refusal never ends the leg: the cashier can pick another or cancel.
            return self::prompt_observation($action, $this->candidates($this->unsettled($client, (int) $action['created_at'] - self::LOOKBACK_SECONDS), $action), $result);
        } catch (AuthorizeNetException $e) {
            return $this->api_unavailable($e);
        } catch (\RuntimeException $e) {
            return $this->indeterminate('antwc_unavailable', Logger::redact_untrusted_text($e->getMessage()));
        }
    }

    public function cancel(string $ref)
    {
        $action = $this->action_for($ref);
        if ($action === null) {
            return 'final'; // Nothing was ever started for this ref.
        }
        if ($action['claim'] !== null) {
            return new \WP_Error('antwc_cancel_claimed', __('Money was taken; refund it instead.', 'authorize-net-terminal-for-woocommerce'), array('status' => 409));
        }
        // A tap may already be on its way to the list; the next fetch decides, and completion wins.
        if (!$action['cancelled'] && $action['cancel_requested_at'] === null) {
            $this->actions->request_cancel((string) $action['row_id'], $this->now());
        }

        return 'requested';
    }

    public function refund(array $row, int $refund_id, string $amount)
    {
        $refs = isset($row['provider_refs']) && is_array($row['provider_refs']) ? $row['provider_refs'] : array();
        $trans_id = isset($refs['transaction_id']) && is_scalar($refs['transaction_id']) ? (string) $refs['transaction_id'] : '';
        if (preg_match('/^\d{1,20}$/', $trans_id) !== 1) {
            return new \WP_Error('antwc_refund_no_transaction', __('This payment has no Authorize.net transaction to refund.', 'authorize-net-terminal-for-woocommerce'), array('status' => 400));
        }
        if (!empty($refs['mode']) && $refs['mode'] !== Settings::environment()) {
            return new \WP_Error('antwc_refund_mode_mismatch', __('This payment was taken in the other Authorize.net environment.', 'authorize-net-terminal-for-woocommerce'), array('status' => 409));
        }
        $currency = strtoupper((string) ($row['currency'] ?? ''));
        $minor = Money_Units::minor($amount, $currency);
        if ($minor <= 0) {
            return new \WP_Error('antwc_refund_invalid_amount', __('The refund amount must be more than zero.', 'authorize-net-terminal-for-woocommerce'), array('status' => 400));
        }

        try {
            $client = $this->client();
            $details = $client->transaction_details($trans_id);
            $status = (string) ($details['transactionStatus'] ?? '');

            if ($status === 'capturedPendingSettlement') {
                $prior = isset($row['refunded_amount']) && is_scalar($row['refunded_amount']) && Money_Units::minor((string) $row['refunded_amount'], $currency) > 0;
                if ($prior || $minor !== TransactionVerifier::settle_minor($details['settleAmount'] ?? null, $currency)) {
                    return new \WP_Error('antwc_refund_unsettled', __('Authorize.net settles this payment overnight. Refund the full amount to void it now, or refund part of it after it settles.', 'authorize-net-terminal-for-woocommerce'), array('status' => 409));
                }

                return $this->refund_result($client->void($trans_id, 'R' . $refund_id), $trans_id, 'void', $trans_id);
            }
            if ($status === 'settledSuccessfully') {
                $card = $details['payment']['creditCard'] ?? array();
                $last4 = TransactionVerifier::last4(is_array($card) && isset($card['cardNumber']) && is_string($card['cardNumber']) ? $card['cardNumber'] : '');
                if ($last4 === '') {
                    return self::refund_unavailable();
                }
                $response = $client->refund($trans_id, Money_Units::major($minor, $currency), $last4, 'WCPOS-' . self::order_id($row, $refund_id), 'R' . $refund_id);
                if (in_array(self::DUPLICATE_ERROR, self::error_codes($response), true)) {
                    return new \WP_Error('antwc_refund_duplicate', __('An identical refund was sent in the last 8 hours. Check the Authorize.net Merchant Interface before trying again.', 'authorize-net-terminal-for-woocommerce'), array('status' => 409));
                }

                return $this->refund_result($response, $trans_id, 'refund', null);
            }
            if ($status === 'voided') {
                return new \WP_Error('antwc_refund_voided', __('This Authorize.net payment was already voided.', 'authorize-net-terminal-for-woocommerce'), array('status' => 409));
            }

            return self::refund_unavailable();
        } catch (AuthorizeNetException $e) {
            if ($e->isIndeterminate()) {
                return $this->indeterminate('antwc_api_unavailable', $e->getMessage());
            }

            return new \WP_Error('antwc_api_error', $e->getMessage(), array('status' => 502, 'detail' => array('code' => $e->getApiCode())));
        } catch (\RuntimeException $e) {
            return $this->indeterminate('antwc_unavailable', Logger::redact_untrusted_text($e->getMessage()));
        }
    }

    public function fetch_refund(array $row, array $allocation)
    {
        $ref = isset($allocation['provider_ref']) && is_scalar($allocation['provider_ref']) ? (string) $allocation['provider_ref'] : '';
        if (preg_match('/^\d{1,20}$/', $ref) !== 1) {
            return new \WP_Error('antwc_refund_no_transaction', __('This refund has no Authorize.net transaction to read.', 'authorize-net-terminal-for-woocommerce'), array('status' => 400));
        }
        $refs = isset($row['provider_refs']) && is_array($row['provider_refs']) ? $row['provider_refs'] : array();
        if (isset($refs['mode']) && is_string($refs['mode']) && $refs['mode'] !== '' && $refs['mode'] !== Settings::environment()) {
            return $this->indeterminate('antwc_refund_mode_mismatch', __('This refund was made in the other Authorize.net environment. Switch the environment back to read it, or check the Authorize.net Merchant Interface.', 'authorize-net-terminal-for-woocommerce'));
        }

        try {
            $status = (string) ($this->client()->transaction_details($ref)['transactionStatus'] ?? '');
        } catch (\RuntimeException $e) {
            return $this->indeterminate('antwc_api_unavailable', Logger::redact_untrusted_text($e->getMessage()));
        }
        if (in_array($status, array('refundSettledSuccessfully', 'refundPendingSettlement'), true)) {
            return array('status' => 'succeeded', 'provider_ref' => $ref);
        }
        if (in_array($status, array('declined', 'voided', 'generalError'), true)) {
            return array('status' => 'failed', 'provider_ref' => $ref);
        }

        return array('status' => 'pending', 'provider_ref' => $ref);
    }

    public function diagnostics(\WC_Payment_Gateway $gateway): array
    {
        $login = Settings::api_login_id();
        $currencies = get_transient(AuthorizeNetClient::currency_cache_key(Settings::endpoint(), $login));
        $last = get_option('antwc_last_api_error', null);

        return array(
            'Environment' => Settings::environment(),
            'API Login ID' => $login === '' ? 'not set' : Settings::mask($login),
            'Transaction key' => Settings::transaction_key() !== '' ? 'set' : 'not set',
            'Merchant currency' => is_array($currencies) && count($currencies) > 0 ? implode(', ', $currencies) : 'unknown',
            'Last API error' => is_array($last) ? trim(($last['code'] ?? '') . ' ' . ($last['text'] ?? '')) . ' (' . gmdate('Y-m-d H:i', (int) ($last['time'] ?? 0)) . ' UTC)' : 'none',
            'log_source' => Logger::SOURCE,
        );
    }

    /**
     * Verify the picked transaction on its full details, then claim it for this row.
     *
     * @param array<string, mixed> $action
     * @return array<string, mixed> A completed observation, or a refusal (ok false, reason, message).
     */
    private function verify_and_claim(AuthorizeNetClient $client, array $action, string $trans_id): array
    {
        $details = $client->transaction_details($trans_id);
        if (count($details) > 0 && (string) ($details['transId'] ?? '') !== $trans_id) {
            $details = array(); // An answer for some other transaction is no answer.
        }
        $currencies = count($details) > 0 ? $client->merchant_currencies() : array();
        $verdict = $this->verifier->verify(
            $details,
            (string) $action['amount'],
            (string) $action['currency'],
            $currencies,
            (int) $action['created_at'] - self::LOOKBACK_SECONDS,
            $this->now()
        );
        if (empty($verdict['ok'])) {
            $observed = in_array($verdict['reason'], array('amount_mismatch', 'currency_mismatch'), true) ? self::observed_money($action, $details, $currencies) : null;
            if ($observed === null) {
                return $verdict;
            }
            // Wrong money is reported as Authorize.net has it, never claimed: Free fails the row with a note.
            Logger::log('Authorize.net transaction does not match the payment', array('row_id' => (string) $action['row_id'], 'order_id' => (int) $action['order_id'], 'trans_id' => $trans_id, 'reason' => $verdict['reason']), null, 'warning');

            return array(
                'status' => 'completed',
                'amount' => $observed[0],
                'currency' => $observed[1],
                'provider_refs' => array('transaction_id' => $trans_id, 'mode' => (string) $action['mode']),
            );
        }

        $row_id = (string) $action['row_id'];
        $claimed = $this->claims->claim((string) $action['mode'], $trans_id, $row_id, (int) $action['order_id']);
        if (empty($claimed['ok'])) {
            $holder = (int) ($claimed['order_id'] ?? 0);
            $message = $holder > 0
                ? sprintf(__('That payment is already recorded on order #%d.', 'authorize-net-terminal-for-woocommerce'), $holder)
                : __('That payment could not be recorded just now. Pick it again.', 'authorize-net-terminal-for-woocommerce');

            return array('ok' => false, 'reason' => 'claimed', 'message' => $message);
        }

        $current = $this->actions->get($row_id);
        if ($current !== null && $current['claim'] !== null) {
            return $this->completed($current, $current['claim']); // Another request for this row stored first.
        }
        $claim = array(
            'trans_id' => $trans_id,
            'amount' => $verdict['amount'],
            'currency' => $verdict['currency'],
            'card_type' => $verdict['card_type'],
            'last4' => $verdict['last4'],
            'auth_code' => $verdict['auth_code'],
            'submitted_at' => $verdict['submitted_at'],
        );
        $this->actions->set_claim($row_id, $claim);
        Logger::log('Claimed Authorize.net transaction', array('row_id' => $row_id, 'order_id' => (int) $action['order_id'], 'trans_id' => $trans_id));

        return $this->completed($action, $claim);
    }

    /**
     * Candidate transactions for a leg, newest first: captured, same amount, recent enough, not another
     * row's. "ours" marks one this row has already claimed.
     *
     * @param array<int, array<string, mixed>> $list The unsettled list.
     * @param array<string, mixed> $action
     * @return array<int, array<string, mixed>>
     */
    private function candidates(array $list, array $action): array
    {
        $currency = (string) $action['currency'];
        $row_minor = Money_Units::minor((string) $action['amount'], $currency);
        $floor = (int) $action['created_at'] - self::LOOKBACK_SECONDS;
        $candidates = array();

        foreach ($list as $item) {
            $trans_id = isset($item['transId']) && is_scalar($item['transId']) ? (string) $item['transId'] : '';
            if (($item['transactionStatus'] ?? '') !== 'capturedPendingSettlement' || preg_match('/^\d{1,20}$/', $trans_id) !== 1) {
                continue;
            }
            if (TransactionVerifier::settle_minor($item['settleAmount'] ?? null, $currency) !== $row_minor) {
                continue;
            }
            $submitted = TransactionVerifier::parse_utc(isset($item['submitTimeUTC']) && is_string($item['submitTimeUTC']) ? $item['submitTimeUTC'] : '');
            if ($submitted === null || $submitted < $floor) {
                continue;
            }
            if (!$this->claims->available_to((string) $action['mode'], $trans_id, (string) $action['row_id'])) {
                continue;
            }
            $holder = $this->claims->holder((string) $action['mode'], $trans_id);
            $card = isset($item['cardType']) && is_string($item['cardType']) ? $item['cardType'] : (isset($item['accountType']) && is_string($item['accountType']) ? $item['accountType'] : 'Card');
            $candidates[] = array(
                'trans_id' => $trans_id,
                'submitted' => $submitted,
                'ours' => $holder !== null,
                'label' => sprintf(
                    '%s ••%s · %s · #%s',
                    $card,
                    TransactionVerifier::last4(isset($item['accountNumber']) && is_string($item['accountNumber']) ? $item['accountNumber'] : ''),
                    wp_date('H:i:s', $submitted),
                    $trans_id
                ),
            );
        }

        usort($candidates, static function (array $a, array $b) {
            return $b['submitted'] <=> $a['submitted'] ?: strcmp($b['trans_id'], $a['trans_id']);
        });

        return array_slice($candidates, 0, self::MAX_CANDIDATES);
    }

    /**
     * True when a decline of exactly the leg's amount was submitted since the leg started (less skew).
     * Any register's decline counts: failing a live leg only makes the cashier start again.
     *
     * @param array<int, array<string, mixed>> $list
     * @param array<string, mixed> $action
     */
    private static function declined_since(array $list, array $action): bool
    {
        $currency = (string) $action['currency'];
        foreach ($list as $item) {
            $submitted = TransactionVerifier::parse_utc(isset($item['submitTimeUTC']) && is_string($item['submitTimeUTC']) ? $item['submitTimeUTC'] : '');
            if (($item['transactionStatus'] ?? '') === 'declined'
                && TransactionVerifier::settle_minor($item['settleAmount'] ?? null, $currency) === Money_Units::minor((string) $action['amount'], $currency)
                && $submitted !== null && $submitted >= (int) $action['created_at'] - self::AUTO_MATCH_SKEW_SECONDS) {
                return true;
            }
        }

        return false;
    }

    /**
     * The money a mismatched transaction carries: its settleAmount in the order currency's units, and
     * the merchant's currency (the order's when listed, else the first listed). Null when unreadable.
     *
     * @param array<string, mixed> $action
     * @param array<string, mixed> $details
     * @param string[] $currencies
     * @return array{0: string, 1: string}|null
     */
    private static function observed_money(array $action, array $details, array $currencies): ?array
    {
        $currency = (string) $action['currency'];
        $minor = TransactionVerifier::settle_minor($details['settleAmount'] ?? null, $currency);
        if ($minor === null || count($currencies) === 0) {
            return null;
        }

        return array(Money_Units::major($minor, $currency), in_array($currency, $currencies, true) ? $currency : (string) $currencies[0]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function unsettled(AuthorizeNetClient $client, int $not_before): array
    {
        $key = 'antwc_unsettled_' . md5(Settings::endpoint() . '|' . Settings::api_login_id());
        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['not_before'], $cached['list']) && is_int($cached['not_before']) && is_array($cached['list']) && $cached['not_before'] <= $not_before) {
            return $cached['list'];
        }
        $list = $client->unsettled_transactions($not_before);
        set_transient($key, array('not_before' => $not_before, 'list' => $list), self::LIST_CACHE_SECONDS);

        return $list;
    }

    /**
     * The pick-list: no buttons while nothing fits (the common path completes by auto-match).
     *
     * @param array<string, mixed> $action
     * @param array<int, array<string, mixed>> $candidates
     * @param array<string, mixed>|null $refusal
     * @return array<string, mixed>
     */
    private static function prompt_observation(array $action, array $candidates, ?array $refusal): array
    {
        $amount = $action['currency'] . ' ' . $action['amount'];
        $count = count($candidates);
        $lines = $refusal !== null ? array((string) $refusal['message']) : array();
        if ($count === 0) {
            $lines[] = sprintf(__('Take %s in the Authorize.net app. This completes by itself when the payment arrives.', 'authorize-net-terminal-for-woocommerce'), $amount);
        } elseif ($count === 1) {
            $lines[] = sprintf(__('1 Authorize.net payment of %s could be this one. Pick the one you just took.', 'authorize-net-terminal-for-woocommerce'), $amount);
        } else {
            $lines[] = sprintf(__('%1$d Authorize.net payments of %2$s could be this one. Pick the one you just took.', 'authorize-net-terminal-for-woocommerce'), $count, $amount);
        }
        $buttons = array();
        $ids = array();
        foreach ($candidates as $candidate) {
            $ids[] = $candidate['trans_id'];
            $buttons[] = array('id' => 'txn:' . $candidate['trans_id'], 'label' => $candidate['label']);
        }

        return array(
            'status' => 'in_progress',
            'amount' => null,
            'currency' => null,
            'prompt' => array(
                // A revision token: stable while the list (and refusal) is unchanged.
                'id' => 'p' . substr(sha1(implode(',', $ids) . '|' . ($refusal !== null ? (string) $refusal['reason'] : '')), 0, 12),
                'lines' => $lines,
                'buttons' => $buttons,
            ),
        );
    }

    /**
     * What a record already decides without asking Authorize.net, or null.
     *
     * Order as the spec gives it: cancelled, mode changed, stored claim.
     *
     * @param array<string, mixed> $action
     * @return array<string, mixed>|null
     */
    private function settled_state(array $action): ?array
    {
        if ($action['cancelled']) {
            return self::cancelled_observation();
        }
        if ($action['mode'] !== Settings::environment()) {
            return array('status' => 'failed', 'amount' => null, 'currency' => null, 'failure_reason' => 'mode_changed');
        }
        if ($action['claim'] !== null) {
            return $this->completed($action, $action['claim']);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function cancelled_observation(): array
    {
        return array('status' => 'cancelled', 'amount' => null, 'currency' => null, 'failure_reason' => 'cancelled');
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $claim
     * @return array<string, mixed>
     */
    private function completed(array $action, array $claim): array
    {
        $last4 = (string) ($claim['last4'] ?? '');

        return array(
            'status' => 'completed',
            'amount' => (string) $action['amount'],
            'currency' => (string) $action['currency'],
            'provider_refs' => array(
                'transaction_id' => (string) $claim['trans_id'],
                'mode' => (string) $action['mode'],
                'card_last4' => $last4,
            ),
            'receipt' => array(
                'card_label' => (string) ($claim['card_type'] ?? ''),
                'card_last4' => $last4,
                'auth_code' => (string) ($claim['auth_code'] ?? ''),
                'read_method' => 'authorize_net_app',
            ),
        );
    }

    /**
     * @param array<string, mixed> $response transactionResponse plus resultCode.
     * @return array<string, mixed>|\WP_Error
     */
    private function refund_result(array $response, string $trans_id, string $kind, ?string $provider_ref)
    {
        $code = isset($response['responseCode']) && is_scalar($response['responseCode']) ? (string) $response['responseCode'] : '';
        $new_id = isset($response['transId']) && is_scalar($response['transId']) ? (string) $response['transId'] : '';
        $ref = $provider_ref !== null ? $provider_ref : $new_id;

        if (($code === '1' || ($code === '4' && $kind === 'refund')) && preg_match('/^[1-9]\d{0,19}$/', $ref) === 1) {
            return array('status' => $code === '1' ? 'succeeded' : 'pending', 'provider_ref' => $ref);
        }
        if ($code === '2' || $code === '3') {
            Logger::log('Authorize.net ' . $kind . ' refused', array('trans_id' => $trans_id, 'response_code' => $code, 'error_codes' => self::error_codes($response)), null, 'warning');

            return array('status' => 'failed', 'provider_ref' => null);
        }

        // Approved without a reference, held, or unreadable: the outcome is unknown, so never success.
        return $this->indeterminate('antwc_refund_unknown', __('Authorize.net did not confirm the refund. Check the Authorize.net Merchant Interface before trying again.', 'authorize-net-terminal-for-woocommerce'));
    }

    /**
     * @param array<string, mixed> $response
     * @return string[]
     */
    private static function error_codes(array $response): array
    {
        $errors = $response['errors'] ?? array();
        if (is_array($errors) && isset($errors['error'])) {
            $errors = $errors['error'];
        }
        $codes = array();
        foreach (AuthorizeNetClient::as_list($errors) as $error) {
            if (is_array($error) && isset($error['errorCode']) && is_scalar($error['errorCode'])) {
                $codes[] = (string) $error['errorCode'];
            }
        }

        return $codes;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function order_id(array $row, int $refund_id): int
    {
        $order_id = (int) ($row['order_id'] ?? 0);
        if ($order_id <= 0 && function_exists('wc_get_order')) {
            $refund = wc_get_order($refund_id);
            $order_id = is_object($refund) && method_exists($refund, 'get_parent_id') ? (int) $refund->get_parent_id() : 0;
        }

        return $order_id;
    }

    private function api_unavailable(AuthorizeNetException $e): \WP_Error
    {
        $message = $e->getApiCode() === 'E00011'
            ? __('Turn on the Transaction Details API in the Authorize.net Merchant Interface (Account → Settings → Transaction Details API).', 'authorize-net-terminal-for-woocommerce')
            : Logger::redact_untrusted_text($e->getMessage());

        return $this->indeterminate('antwc_api_unavailable', $message);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function action_for(string $ref): ?array
    {
        return strpos($ref, 'antwc:') === 0 ? $this->actions->get(substr($ref, 6)) : null;
    }

    private static function unknown_action(): \WP_Error
    {
        return new \WP_Error('antwc_unknown_action', __('No Authorize.net payment was started for this.', 'authorize-net-terminal-for-woocommerce'), array('status' => 404));
    }

    private static function refund_unavailable(): \WP_Error
    {
        return new \WP_Error('antwc_refund_unavailable', __('Authorize.net cannot refund this payment now. Check the Authorize.net Merchant Interface.', 'authorize-net-terminal-for-woocommerce'), array('status' => 409));
    }

    private function client(): AuthorizeNetClient
    {
        return call_user_func($this->client_factory);
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }
}
