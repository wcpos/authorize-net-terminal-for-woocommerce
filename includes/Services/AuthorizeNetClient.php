<?php

namespace WCPOS\WooCommercePOS\AuthorizeNetTerminal\Services;

use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Logger;

/**
 * Authorize.net JSON API (the XML API's JSON binding). Because the JSON is XML-backed, element order is
 * significant: every payload is built in the schema's order, merchantAuthentication first.
 */
class AuthorizeNetClient
{
    /** Seconds before a call is abandoned (and treated as indeterminate). */
    private const TIMEOUT = 15;
    /** Merchant currencies change rarely; one day. */
    private const CURRENCY_TTL = 86400;
    /** Authorize.net limits refId to 20 characters. */
    private const REF_ID_MAX = 20;
    /** The maximum duplicate window Authorize.net accepts (8 hours): a replayed identical refund is refused with error 11. */
    private const REFUND_DUPLICATE_WINDOW = '28800';
    /** Transactions requested per unsettled-list page. */
    private const LIST_PAGE_SIZE = 50;
    /** At most 1000 transactions, 20 calls, per read. */
    private const LIST_MAX_PAGES = 20;

    /** @var string */
    private $endpoint;
    /** @var string */
    private $login_id;
    /** @var string */
    private $transaction_key;
    /** @var callable */
    private $post;

    public function __construct(string $endpoint, string $login_id, string $transaction_key, ?callable $post = null)
    {
        $this->endpoint = $endpoint;
        $this->login_id = $login_id;
        $this->transaction_key = $transaction_key;
        $this->post = $post === null ? 'wp_remote_post' : $post;
    }

    public static function currency_cache_key(string $endpoint, string $login_id): string
    {
        return 'antwc_currencies_' . md5($endpoint . '|' . $login_id);
    }

    /**
     * Unsettled transactions, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unsettled_transactions(int $not_before): array
    {
        $list = array();
        $seen = array();
        for ($page = 1; $page <= self::LIST_MAX_PAGES; $page++) {
            $response = $this->call('getUnsettledTransactionListRequest', array(
                'sorting' => array('orderBy' => 'submitTimeUTC', 'orderDescending' => true),
                'paging' => array('limit' => (string) self::LIST_PAGE_SIZE, 'offset' => (string) $page),
            ));
            if (self::message_code($response) === 'I00004') {
                break; // "No records found."
            }
            $items = array_values(array_filter(self::as_list($response['transactions'] ?? array()), 'is_array'));
            foreach ($items as $item) {
                if (isset($item['transId']) && is_scalar($item['transId'])) {
                    $id = (string) $item['transId'];
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                }
                $list[] = $item;
            }
            if (count($items) < self::LIST_PAGE_SIZE) {
                break;
            }
            $last = $items[count($items) - 1]['submitTimeUTC'] ?? '';
            $submitted = TransactionVerifier::parse_utc(is_string($last) ? $last : '');
            if ($submitted === null || $submitted < $not_before) {
                break;
            }
        }

        return $list;
    }

    /**
     * @return array<string, mixed>
     */
    public function transaction_details(string $trans_id): array
    {
        $response = $this->call('getTransactionDetailsRequest', array('transId' => $trans_id));

        return isset($response['transaction']) && is_array($response['transaction']) ? $response['transaction'] : array();
    }

    /**
     * Upper-case ISO codes the merchant account settles in; a non-empty answer is cached for a day.
     *
     * @return string[]
     */
    public function merchant_currencies(): array
    {
        $key = self::currency_cache_key($this->endpoint, $this->login_id);
        $cached = get_transient($key);
        if (is_array($cached) && count($cached) > 0) {
            return $cached;
        }

        $response = $this->call('getMerchantDetailsRequest', array());
        $raw = $response['currencies'] ?? array();
        if (is_array($raw) && isset($raw['currency'])) {
            $raw = $raw['currency'];
        }
        $currencies = array();
        foreach (self::as_list($raw) as $code) {
            if (is_string($code) && preg_match('/^[A-Za-z]{3}$/', $code) === 1) {
                $currencies[] = strtoupper($code);
            }
        }
        if (count($currencies) > 0) {
            set_transient($key, $currencies, self::CURRENCY_TTL);
        }

        return $currencies;
    }

    /**
     * @return array<string, mixed> transactionResponse plus resultCode.
     */
    public function void(string $trans_id, string $ref_id): array
    {
        return $this->create_transaction($ref_id, array(
            'transactionType' => 'voidTransaction',
            'refTransId' => $trans_id,
        ));
    }

    /**
     * @return array<string, mixed> transactionResponse plus resultCode.
     */
    public function refund(string $trans_id, string $amount, string $last4, string $invoice, string $ref_id): array
    {
        return $this->create_transaction($ref_id, array(
            'transactionType' => 'refundTransaction',
            'amount' => $amount,
            'payment' => array('creditCard' => array('cardNumber' => $last4, 'expirationDate' => 'XXXX')),
            'refTransId' => $trans_id,
            'order' => array('invoiceNumber' => $invoice),
            'transactionSettings' => array('setting' => array(
                array('settingName' => 'duplicateWindow', 'settingValue' => self::REFUND_DUPLICATE_WINDOW),
            )),
        ));
    }

    /**
     * @param array<string, mixed> $transaction_request
     * @return array<string, mixed>
     */
    private function create_transaction(string $ref_id, array $transaction_request): array
    {
        $response = $this->call('createTransactionRequest', array(
            'refId' => substr($ref_id, 0, self::REF_ID_MAX),
            'transactionRequest' => $transaction_request,
        ), true);
        $result = $response['transactionResponse'];
        $result['resultCode'] = (string) ($response['messages']['resultCode'] ?? '');

        return $result;
    }

    /**
     * POST one request. With $transaction, a resultCode "Error" that carries a transactionResponse is
     * returned so the caller can read the response code and errors.
     *
     * @param array<string, mixed> $body Request elements after merchantAuthentication, in schema order.
     * @return array<string, mixed>
     */
    private function call(string $request, array $body, bool $transaction = false): array
    {
        $payload = array($request => array_merge(
            array('merchantAuthentication' => array('name' => $this->login_id, 'transactionKey' => $this->transaction_key)),
            $body
        ));
        $json = wp_json_encode($payload);
        if (!is_string($json)) {
            throw new AuthorizeNetException('Could not encode the Authorize.net request.', '', false);
        }

        $response = call_user_func($this->post, $this->endpoint, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => $json,
            'timeout' => self::TIMEOUT,
        ));
        if (is_wp_error($response)) {
            throw new AuthorizeNetException('Authorize.net could not be reached: ' . $response->get_error_message(), '', true);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status > 299) {
            throw new AuthorizeNetException('Authorize.net answered HTTP ' . $status . '.', '', true);
        }
        $raw = (string) wp_remote_retrieve_body($response);
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3); // Authorize.net prefixes its JSON with a UTF-8 BOM.
        }
        $decoded = $raw === '' ? null : json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['messages']) || !is_array($decoded['messages'])) {
            throw new AuthorizeNetException('Authorize.net sent an unreadable answer.', '', true);
        }

        $result_code = (string) ($decoded['messages']['resultCode'] ?? '');
        if ($result_code === 'Ok') {
            if ($transaction && !(isset($decoded['transactionResponse']) && is_array($decoded['transactionResponse']))) {
                throw new AuthorizeNetException('Authorize.net accepted the request without a transaction response.', '', true);
            }

            return $decoded;
        }
        if ($result_code !== 'Error') {
            throw new AuthorizeNetException('Authorize.net sent an unreadable answer.', '', true);
        }
        if ($transaction && isset($decoded['transactionResponse']) && is_array($decoded['transactionResponse'])) {
            return $decoded;
        }

        $code = self::message_code($decoded);
        $text = Logger::redact_untrusted_text(self::message_text($decoded));
        if (function_exists('update_option')) {
            update_option('antwc_last_api_error', array('code' => $code, 'text' => substr($text, 0, 200), 'time' => time()), false);
        }
        Logger::log('Authorize.net refused ' . $request, array('code' => $code, 'text' => $text), null, 'warning');

        throw new AuthorizeNetException('Authorize.net: ' . $text . ($code !== '' ? ' (' . $code . ')' : ''), $code, false);
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function message_code(array $response): string
    {
        $messages = self::as_list($response['messages']['message'] ?? array());

        return isset($messages[0]['code']) && is_scalar($messages[0]['code']) ? (string) $messages[0]['code'] : '';
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function message_text(array $response): string
    {
        $messages = self::as_list($response['messages']['message'] ?? array());

        return isset($messages[0]['text']) && is_scalar($messages[0]['text']) ? (string) $messages[0]['text'] : 'request refused';
    }

    /**
     * The XML binding sends a one-element list as a bare object; normalise to a list.
     *
     * @param mixed $value
     * @return array<int, mixed>
     */
    public static function as_list($value): array
    {
        if (!is_array($value)) {
            return $value === null || $value === '' ? array() : array($value);
        }
        if (count($value) === 0) {
            return array();
        }

        return array_keys($value) === range(0, count($value) - 1) ? $value : array($value);
    }
}
