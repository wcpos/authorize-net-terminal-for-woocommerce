<?php

use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Services\AuthorizeNetClient;
use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Services\AuthorizeNetException;
use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Settings;

antwc_test_reset();
$client = new AuthorizeNetClient(Settings::SANDBOX_ENDPOINT, 'test-login', 'test-key');
$auth = '"merchantAuthentication":{"name":"test-login","transactionKey":"test-key"}';

$client->unsettled_transactions(ANTWC_TEST_NOW - 900);
antwc_assert_same(
    '{"getUnsettledTransactionListRequest":{' . $auth . ',"sorting":{"orderBy":"submitTimeUTC","orderDescending":true},"paging":{"limit":"50","offset":"1"}}}',
    Antwc_Fake_Http::$requests[0]['args']['body'],
    'client: unsettled list payload key order'
);
antwc_assert_same(Settings::SANDBOX_ENDPOINT, Antwc_Fake_Http::$requests[0]['url'], 'client: posts to the sandbox endpoint');
antwc_assert_same(array('Content-Type' => 'application/json'), Antwc_Fake_Http::$requests[0]['args']['headers'], 'client: JSON content type');
antwc_assert_same(15, Antwc_Fake_Http::$requests[0]['args']['timeout'], 'client: timeout 15');

Antwc_Fake_Http::$details['4001'] = array('transId' => '4001');
$client->transaction_details('4001');
antwc_assert_same('{"getTransactionDetailsRequest":{' . $auth . ',"transId":"4001"}}', Antwc_Fake_Http::$requests[1]['args']['body'], 'client: details payload key order');

$client->merchant_currencies();
antwc_assert_same('{"getMerchantDetailsRequest":{' . $auth . '}}', Antwc_Fake_Http::$requests[2]['args']['body'], 'client: merchant details payload key order');

$approved = array('transactionResponse' => array('responseCode' => '1', 'transId' => '5001'), 'messages' => array('resultCode' => 'Ok', 'message' => array(array('code' => 'I00001', 'text' => 'Successful.'))));
Antwc_Fake_Http::$create[] = $approved;
$client->void('4001', 'R77');
antwc_assert_same(
    '{"createTransactionRequest":{' . $auth . ',"refId":"R77","transactionRequest":{"transactionType":"voidTransaction","refTransId":"4001"}}}',
    Antwc_Fake_Http::$requests[3]['args']['body'],
    'client: void payload key order'
);

Antwc_Fake_Http::$create[] = $approved;
$refund = $client->refund('4001', '12.34', '1111', 'WCPOS-123', 'R77-and-a-very-long-reference');
antwc_assert_same(
    '{"createTransactionRequest":{' . $auth . ',"refId":"R77-and-a-very-long-","transactionRequest":{"transactionType":"refundTransaction","amount":"12.34","payment":{"creditCard":{"cardNumber":"1111","expirationDate":"XXXX"}},"refTransId":"4001","order":{"invoiceNumber":"WCPOS-123"},"transactionSettings":{"setting":[{"settingName":"duplicateWindow","settingValue":"28800"}]}}}}',
    Antwc_Fake_Http::$requests[4]['args']['body'],
    'client: refund payload key order, refId truncated to 20'
);
antwc_assert_same(array('responseCode' => '1', 'transId' => '5001', 'resultCode' => 'Ok'), $refund, 'client: transactionResponse plus resultCode');

// BOM stripping: the fake always prefixes one; a body that decoded proves it was stripped.
antwc_test_reset();
Antwc_Fake_Http::$raw = "\xEF\xBB\xBF" . json_encode(array('transaction' => array('transId' => '9'), 'messages' => array('resultCode' => 'Ok', 'message' => array(array('code' => 'I00001')))));
antwc_assert_same(array('transId' => '9'), $client->transaction_details('9'), 'client: BOM stripped before json_decode');

antwc_test_reset();
antwc_assert_same(array(), $client->unsettled_transactions(ANTWC_TEST_NOW - 900), 'client: I00004 is an empty list');

antwc_test_reset();
Antwc_Fake_Http::$unsettled[] = array('transId' => '1', 'settleAmount' => 1.5);
antwc_assert_same(array(array('transId' => '1', 'settleAmount' => 1.5)), $client->unsettled_transactions(ANTWC_TEST_NOW - 900), 'client: transactions list returned');

antwc_test_reset();
for ($i = 1; $i <= 120; $i++) {
    antwc_test_tap((string) $i, 12.34, ANTWC_TEST_NOW - $i);
}
$list = $client->unsettled_transactions(ANTWC_TEST_NOW - 900);
$offsets = array();
foreach (Antwc_Fake_Http::$requests as $request) {
    $offsets[] = $request['payload']['getUnsettledTransactionListRequest']['paging']['offset'];
}
antwc_assert_same(
    array(Antwc_Fake_Http::$unsettled, 3, array('1', '2', '3')),
    array($list, Antwc_Fake_Http::count('getUnsettledTransactionListRequest'), $offsets),
    'client: unsettled list pages until a short page'
);

antwc_test_reset();
for ($i = 1; $i <= 70; $i++) {
    antwc_test_tap((string) $i, 12.34, ANTWC_TEST_NOW - ($i < 50 ? $i : 900 + $i));
}
$list = $client->unsettled_transactions(ANTWC_TEST_NOW - 900);
antwc_assert_same(
    array(array_slice(Antwc_Fake_Http::$unsettled, 0, 50), 1),
    array($list, Antwc_Fake_Http::count('getUnsettledTransactionListRequest')),
    'client: unsettled list stops at not_before'
);

antwc_test_reset();
for ($i = 1; $i <= 60; $i++) {
    antwc_test_tap((string) ($i === 51 ? 50 : $i), 12.34, ANTWC_TEST_NOW - $i);
}
antwc_assert_same(
    array_merge(array_slice(Antwc_Fake_Http::$unsettled, 0, 50), array_slice(Antwc_Fake_Http::$unsettled, 51)),
    $client->unsettled_transactions(ANTWC_TEST_NOW - 900),
    'client: unsettled list drops a transId repeated across pages'
);

antwc_test_reset();
for ($i = 1; $i <= 1100; $i++) {
    antwc_test_tap((string) $i, 12.34, ANTWC_TEST_NOW - intdiv($i, 2));
}
$list = $client->unsettled_transactions(ANTWC_TEST_NOW - 900);
antwc_assert_same(
    array(array_slice(Antwc_Fake_Http::$unsettled, 0, 1000), 20),
    array($list, Antwc_Fake_Http::count('getUnsettledTransactionListRequest')),
    'client: unsettled list stops at 20 pages'
);

/**
 * @return AuthorizeNetException|null
 */
function antwc_client_throws(callable $call)
{
    try {
        $call();
    } catch (AuthorizeNetException $e) {
        return $e;
    }

    return null;
}

antwc_test_reset();
Antwc_Fake_Http::$raw = json_encode(array('messages' => array('resultCode' => 'Error', 'message' => array(array('code' => 'E00007', 'text' => 'User authentication failed due to invalid authentication values.')))));
$e = antwc_client_throws(static function () use ($client) {
    $client->transaction_details('1');
});
antwc_assert_same(true, $e !== null && !$e->isIndeterminate(), 'client: E00007 is a determinate exception');
antwc_assert_same('E00007', $e->getApiCode(), 'client: E00007 api code');
antwc_assert_same('E00007', get_option('antwc_last_api_error')['code'], 'client: determinate error recorded for diagnostics');

antwc_test_reset();
Antwc_Fake_Http::$failure = 'wp_error';
$e = antwc_client_throws(static function () use ($client) {
    $client->unsettled_transactions(ANTWC_TEST_NOW - 900);
});
antwc_assert_same(true, $e !== null && $e->isIndeterminate(), 'client: WP_Error is indeterminate');

Antwc_Fake_Http::$failure = 'http_500';
$e = antwc_client_throws(static function () use ($client) {
    $client->void('1', 'R1');
});
antwc_assert_same(true, $e !== null && $e->isIndeterminate(), 'client: HTTP 500 is indeterminate');

Antwc_Fake_Http::$failure = null;
Antwc_Fake_Http::$raw = 'not json';
$e = antwc_client_throws(static function () use ($client) {
    $client->transaction_details('1');
});
antwc_assert_same(true, $e !== null && $e->isIndeterminate(), 'client: invalid JSON is indeterminate');

antwc_test_reset();
Antwc_Fake_Http::$create[] = array(
    'transactionResponse' => array('responseCode' => '3', 'transId' => '0', 'errors' => array(array('errorCode' => '11', 'errorText' => 'A duplicate transaction has been submitted.'))),
    'messages' => array('resultCode' => 'Error', 'message' => array(array('code' => 'E00027', 'text' => 'The transaction was unsuccessful.'))),
);
$response = $client->refund('4001', '1.00', '1111', 'WCPOS-1', 'R1');
antwc_assert_same(array('3', 'Error', '11'), array($response['responseCode'], $response['resultCode'], $response['errors'][0]['errorCode']), 'client: transactionResponse returned on an Error result');

antwc_test_reset();
Antwc_Fake_Http::$currencies = array('usd', 'CAD', 'bogus');
antwc_assert_same(array('USD', 'CAD'), $client->merchant_currencies(), 'client: merchant currencies upper-cased');
$client->merchant_currencies();
antwc_assert_same(1, Antwc_Fake_Http::count('getMerchantDetailsRequest'), 'client: merchant currencies cached');
