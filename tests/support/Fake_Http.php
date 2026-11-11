<?php

/**
 * Stands in for wp_remote_post: routes on the request name (the payload's first key) and records every call.
 */
final class Antwc_Fake_Http
{
    /** @var array<int, array<string, mixed>> */
    public static $requests = array();
    /** @var array<int, array<string, mixed>> Items of the unsettled list. */
    public static $unsettled = array();
    /** @var array<string, array<string, mixed>> transId => getTransactionDetails "transaction". */
    public static $details = array();
    /** @var string[] */
    public static $currencies = array('USD');
    /** @var array<int, array<string, mixed>> Queued createTransaction answers (whole decoded bodies). */
    public static $create = array();
    /** @var string|null 'wp_error' or 'http_500' fails every call; an array body is sent as given. */
    public static $failure = null;
    /** @var string|null A raw body sent for every call. */
    public static $raw = null;

    public static function reset(): void
    {
        self::$requests = array();
        self::$unsettled = array();
        self::$details = array();
        self::$currencies = array('USD');
        self::$create = array();
        self::$failure = null;
        self::$raw = null;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public static function post(string $url, array $args)
    {
        $payload = json_decode((string) $args['body'], true);
        $name = is_array($payload) ? (string) array_key_first($payload) : '';
        self::$requests[] = array('url' => $url, 'args' => $args, 'name' => $name, 'payload' => $payload);

        if (self::$failure === 'wp_error') {
            return new WP_Error('http_request_failed', 'cURL error 28: timed out');
        }
        if (self::$failure === 'http_500') {
            return array('response' => array('code' => 500), 'body' => 'oops');
        }
        if (self::$raw !== null) {
            return array('response' => array('code' => 200), 'body' => self::$raw);
        }

        $body = self::answer($name, is_array($payload) ? $payload[$name] : array());

        // Authorize.net prefixes its JSON with a UTF-8 BOM.
        return array('response' => array('code' => 200), 'body' => "\xEF\xBB\xBF" . json_encode($body));
    }

    public static function count(string $name = ''): int
    {
        $n = 0;
        foreach (self::$requests as $request) {
            if ($name === '' || $request['name'] === $name) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function last(string $name): ?array
    {
        for ($i = count(self::$requests) - 1; $i >= 0; $i--) {
            if (self::$requests[$i]['name'] === $name) {
                return self::$requests[$i];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function answer(string $name, array $body): array
    {
        $ok = array('resultCode' => 'Ok', 'message' => array(array('code' => 'I00001', 'text' => 'Successful.')));
        if ($name === 'getUnsettledTransactionListRequest') {
            if (count(self::$unsettled) === 0) {
                return array('messages' => array('resultCode' => 'Ok', 'message' => array(array('code' => 'I00004', 'text' => 'No records found.'))));
            }

            return array('transactions' => self::$unsettled, 'messages' => $ok);
        }
        if ($name === 'getTransactionDetailsRequest') {
            $id = (string) ($body['transId'] ?? '');
            if (!isset(self::$details[$id])) {
                return array('messages' => array('resultCode' => 'Error', 'message' => array(array('code' => 'E00040', 'text' => 'The record cannot be found.'))));
            }

            return array('transaction' => self::$details[$id], 'messages' => $ok);
        }
        if ($name === 'getMerchantDetailsRequest') {
            return array('currencies' => self::$currencies, 'messages' => $ok);
        }
        if ($name === 'createTransactionRequest' && count(self::$create) > 0) {
            return array_shift(self::$create);
        }

        return array('messages' => array('resultCode' => 'Error', 'message' => array(array('code' => 'E00003', 'text' => 'Unexpected request.'))));
    }
}
