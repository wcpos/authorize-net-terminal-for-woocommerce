<?php

namespace WCPOS\WooCommercePOS\AuthorizeNetTerminal\Tests\Conformance;

/**
 * A scripted Authorize.net sandbox behind WordPress's HTTP layer (`pre_http_request`): the JSON API at
 * apitest.authorize.net, routed by the top-level request name, with the UTF-8 BOM the real API puts
 * before every body. The phone app is the scripted part: each adapter fetch lets the next batch of app
 * transactions appear in the unsettled list. Any other authorize.net request (production included) or
 * request name throws and is kept in $unexpected, so a stray call fails the test loudly.
 */
final class Fake_Authorize_Net_Transport
{
    public const HOST = 'apitest.authorize.net';
    public const PATH = '/xml/v1/request.api';
    public const LOGIN = 'conformance-login';
    public const KEY = 'conformance-transaction-key';

    /** Every unexpected request, for uninstall() to fail on. */
    public $unexpected = array();
    /** Short names of the requests answered since the last take_sent(). */
    private $sent = array();
    /** @var array<string, array<string, mixed>> transId => the transaction as getTransactionDetails returns it */
    private $transactions = array();
    /** Batches of app transactions ("amount" or "amount:status"), one per adapter fetch. */
    private $batches = array();
    private $refund_code = '1';
    private $currencies = array('USD');
    /** Fields getTransactionDetails reports differently from the list (a scenario's mismatch). */
    private $details_overrides = array();
    private $seq = 0;

    /**
     * Arm a scenario: the app transactions each successive fetch finds newly listed, the responseCode a
     * refund gets, the currencies the merchant account settles in, and the details fields that differ
     * from what the list shows.
     *
     * @param array<int, array<int, string>> $batches
     * @param string[] $currencies
     * @param array<string, mixed> $details_overrides
     */
    public function script(array $batches, string $refund_code = '1', array $currencies = array('USD'), array $details_overrides = array()): void
    {
        $this->batches = $batches;
        $this->refund_code = $refund_code;
        $this->currencies = $currencies;
        $this->details_overrides = $details_overrides;
    }

    /** Called on entry to fetch(): the next batch of app taps reaches the unsettled list. */
    public function advance(): void
    {
        foreach (count($this->batches) > 0 ? array_shift($this->batches) : array() as $tap) {
            $parts = explode(':', $tap);
            $this->tap($parts[0], $parts[1] ?? 'capturedPendingSettlement');
        }
    }

    /** Overnight settlement: every captured transaction becomes settled (and so refundable in part). */
    public function settle(): void
    {
        foreach ($this->transactions as &$transaction) {
            if ($transaction['transactionStatus'] === 'capturedPendingSettlement') {
                $transaction['transactionStatus'] = 'settledSuccessfully';
            }
        }
        unset($transaction);
    }

    /** @return string[] */
    public function take_sent(): array
    {
        $sent = $this->sent;
        $this->sent = array();

        return $sent;
    }

    /**
     * @param mixed $pre
     * @param array<string, mixed> $args
     * @return mixed
     */
    public function handle($pre, array $args, string $url)
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if ($host !== 'authorize.net' && substr($host, -strlen('.authorize.net')) !== '.authorize.net') {
            return $pre;
        }
        $body = json_decode((string) ($args['body'] ?? ''), true);
        $name = is_array($body) && count($body) === 1 ? (string) array_keys($body)[0] : '';
        $request = $name !== '' && is_array($body[$name]) ? $body[$name] : array();
        $routes = array(
            'getUnsettledTransactionListRequest' => 'unsettled',
            'getTransactionDetailsRequest' => 'details',
            'getMerchantDetailsRequest' => 'merchant',
            'createTransactionRequest' => 'transaction',
        );
        if ($host !== self::HOST || (string) wp_parse_url($url, PHP_URL_PATH) !== self::PATH || strtoupper((string) ($args['method'] ?? 'POST')) !== 'POST' || !isset($routes[$name])) {
            $this->unexpected[] = $url . ' ' . $name;
            throw new \LogicException('Unexpected Authorize.net request: ' . $url . ' ' . $name);
        }
        $auth = $request['merchantAuthentication'] ?? array();
        if (($auth['name'] ?? '') !== self::LOGIN || ($auth['transactionKey'] ?? '') !== self::KEY) {
            return self::answer(array(), 'Error', 'E00007', 'User authentication failed due to invalid authentication values.');
        }

        return $this->{$routes[$name]}($request);
    }

    /** @param array<string, mixed> $request */
    private function unsettled(array $request)
    {
        $this->sent[] = 'list';
        $list = array();
        foreach (array_reverse($this->transactions) as $t) {
            if (in_array($t['transactionStatus'], array('capturedPendingSettlement', 'declined', 'voided'), true)) {
                $list[] = array(
                    'transId' => $t['transId'],
                    'submitTimeUTC' => $t['submitTimeUTC'],
                    'transactionStatus' => $t['transactionStatus'],
                    'accountType' => $t['payment']['creditCard']['cardType'],
                    'accountNumber' => $t['payment']['creditCard']['cardNumber'],
                    'settleAmount' => $t['settleAmount'],
                    'marketType' => 'eCommerce',
                    'product' => 'Card Not Present',
                );
            }
        }

        return count($list) > 0 ? self::answer(array('transactions' => $list, 'totalNumInResultSet' => count($list))) : self::answer(array(), 'Ok', 'I00004', 'No records found.');
    }

    /** @param array<string, mixed> $request */
    private function details(array $request)
    {
        $this->sent[] = 'details';
        $id = (string) ($request['transId'] ?? '');

        return isset($this->transactions[$id]) ? self::answer(array('transaction' => array_merge($this->transactions[$id], $this->details_overrides))) : self::answer(array(), 'Error', 'E00040', 'The record cannot be found.');
    }

    /** @param array<string, mixed> $request */
    private function merchant(array $request)
    {
        $this->sent[] = 'merchant';

        return self::answer(array('isTestMode' => false, 'merchantName' => 'Conformance', 'currencies' => $this->currencies));
    }

    /** @param array<string, mixed> $request */
    private function transaction(array $request)
    {
        $tr = $request['transactionRequest'] ?? array();
        $type = (string) ($tr['transactionType'] ?? '');
        $ref = (string) ($tr['refTransId'] ?? '');
        if (!in_array($type, array('voidTransaction', 'refundTransaction'), true) || !isset($this->transactions[$ref])) {
            $this->unexpected[] = 'createTransactionRequest ' . $type . ' ' . $ref;
            throw new \LogicException('Unexpected Authorize.net transaction: ' . $type . ' for ' . $ref);
        }
        $this->sent[] = $type === 'voidTransaction' ? 'void' : 'refund';
        $original = &$this->transactions[$ref];
        if ($type === 'voidTransaction') {
            $ok = $original['transactionStatus'] === 'capturedPendingSettlement';
            $original['transactionStatus'] = $ok ? 'voided' : $original['transactionStatus'];

            return self::transaction_answer($ok ? '1' : '3', $ok ? $ref : '0', $ref);
        }
        if ($original['transactionStatus'] !== 'settledSuccessfully' || ($tr['payment']['creditCard']['cardNumber'] ?? '') !== '1111') {
            return self::transaction_answer('3', '0', $ref); // 54: the referenced transaction does not meet the criteria for issuing a credit.
        }
        $new = $this->refund_code === '2' ? '0' : $this->next_id();

        return self::transaction_answer($this->refund_code, $new, $ref);
    }

    private function tap(string $amount, string $status): void
    {
        $id = $this->next_id();
        $this->transactions[$id] = array(
            'transId' => $id,
            'submitTimeUTC' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'transactionType' => 'authCaptureTransaction',
            'transactionStatus' => $status,
            'responseCode' => $status === 'declined' ? 2 : 1,
            'authCode' => $status === 'declined' ? '000000' : 'A1B2C3',
            'authAmount' => (float) $amount,
            'settleAmount' => (float) $amount,
            'payment' => array('creditCard' => array('cardNumber' => 'XXXX1111', 'expirationDate' => 'XXXX', 'cardType' => 'Visa')),
            'marketType' => 'eCommerce',
            'product' => 'Card Not Present',
        );
    }

    private function next_id(): string
    {
        return (string) (40000000001 + $this->seq++);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function answer(array $body, string $result = 'Ok', string $code = 'I00001', string $text = 'Successful.'): array
    {
        $body['messages'] = array('resultCode' => $result, 'message' => array(array('code' => $code, 'text' => $text)));

        return array(
            'headers' => array('content-type' => 'application/json; charset=utf-8'),
            'body' => "\xEF\xBB\xBF" . wp_json_encode($body),
            'response' => array('code' => 200, 'message' => 'OK'),
            'cookies' => array(),
            'filename' => null,
        );
    }

    /** A createTransaction answer: 1 approved, 2 declined, 3 error, 4 held for review. */
    private static function transaction_answer(string $code, string $trans_id, string $ref): array
    {
        $response = array('responseCode' => $code, 'authCode' => '', 'transId' => $trans_id, 'refTransID' => $ref, 'accountNumber' => 'XXXX1111', 'accountType' => 'Visa');
        if ($code === '1' || $code === '4') {
            $response['messages'] = array(array('code' => $code === '1' ? '1' : '253', 'description' => $code === '1' ? 'This transaction has been approved.' : 'Your order has been received.'));
        } else {
            $response['errors'] = array(array('errorCode' => $code === '2' ? '2' : '54', 'errorText' => $code === '2' ? 'This transaction has been declined.' : 'The referenced transaction does not meet the criteria for issuing a credit.'));
        }
        $ok = $code === '1' || $code === '4';

        return self::answer(array('transactionResponse' => $response), $ok ? 'Ok' : 'Error', $ok ? 'I00001' : 'E00027', $ok ? 'Successful.' : 'The transaction was unsuccessful.');
    }
}

/** The answer to an adapter's create, lost on its way back to Pro after the adapter stored the action. */
final class Lost_Create_Response extends \Exception
{
}
