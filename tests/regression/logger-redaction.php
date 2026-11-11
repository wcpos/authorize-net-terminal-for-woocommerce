<?php

use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Logger;
use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Services\AuthorizeNetClient;
use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Services\AuthorizeNetException;
use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Settings;

antwc_test_reset();

function antwc_log_text(): string
{
    return json_encode($GLOBALS['antwc_test_log']);
}

Logger::log('payload', array(
    'createTransactionRequest' => array(
        'merchantAuthentication' => array('name' => 'test-login', 'transactionKey' => 'test-key'),
        'transactionRequest' => array('payment' => array('creditCard' => array('cardNumber' => '4111111111111111'))),
    ),
    'transaction_key' => 'test-key',
    'api_login_id' => 'test-login',
    'trans_id' => '4001',
));
$text = antwc_log_text();
antwc_assert_same(false, strpos($text, 'test-key'), 'logger-redaction: transactionKey never logged');
antwc_assert_same(false, strpos($text, 'test-login'), 'logger-redaction: login never logged');
antwc_assert_same(false, strpos($text, '4111111111111111'), 'logger-redaction: cardNumber never logged');
antwc_assert_same(true, strpos($text, '4001') !== false, 'logger-redaction: non-secret context kept');

$GLOBALS['antwc_test_log'] = array();
Logger::log('Request failed: transactionKey=test-key name test-login', array('message' => '"transactionKey":"test-key"'));
antwc_assert_same(false, strpos(antwc_log_text(), 'test-key'), 'logger-redaction: transactionKey in free text redacted');

// A determinate API error is logged and recorded by the client; neither carries the credentials.
antwc_test_reset();
Antwc_Fake_Http::$raw = json_encode(array('messages' => array('resultCode' => 'Error', 'message' => array(array('code' => 'E00007', 'text' => 'User authentication failed: transactionKey test-key')))));
$client = new AuthorizeNetClient(Settings::SANDBOX_ENDPOINT, 'test-login', 'test-key');
try {
    $client->transaction_details('1');
} catch (AuthorizeNetException $e) {
    antwc_assert_same(false, strpos($e->getMessage(), 'test-key'), 'logger-redaction: exception message redacted');
}
antwc_assert_same(true, count($GLOBALS['antwc_test_log']) > 0, 'logger-redaction: API error logged');
antwc_assert_same(false, strpos(antwc_log_text(), 'test-key'), 'logger-redaction: API error log has no transactionKey');
antwc_assert_same(false, strpos(antwc_log_text(), 'test-login'), 'logger-redaction: API error log has no login');
antwc_assert_same(false, strpos(json_encode(get_option('antwc_last_api_error')), 'test-key'), 'logger-redaction: last API error has no transactionKey');
