<?php

$now = ANTWC_TEST_NOW;
$provider = antwc_test_provider();

/**
 * @return array<string, mixed> A captured ledger row for transaction 4001 (USD 12.34, order 101).
 */
function antwc_refund_row(array $refs = array(), string $refunded = '0'): array
{
    return array(
        'id' => 'row-a',
        'order_id' => 101,
        'amount' => '12.34',
        'currency' => 'USD',
        'refunded_amount' => $refunded,
        'provider_refs' => array_merge(array('transaction_id' => '4001', 'mode' => 'sandbox', 'card_last4' => '1111'), $refs),
    );
}

/**
 * @param array<string, mixed> $response transactionResponse
 */
function antwc_refund_answer(array $response, string $result = 'Ok'): void
{
    Antwc_Fake_Http::$create[] = array('transactionResponse' => $response, 'messages' => array('resultCode' => $result, 'message' => array(array('code' => $result === 'Ok' ? 'I00001' : 'E00027'))));
}

function antwc_refund_setup(string $status): void
{
    antwc_test_reset();
    Antwc_Fake_Http::$details['4001'] = array_merge(antwc_test_details('4001', 12.34, ANTWC_TEST_NOW - 3600), array('transactionStatus' => $status));
}

antwc_refund_setup('capturedPendingSettlement');
antwc_refund_answer(array('responseCode' => '1', 'transId' => '4001'));
antwc_assert_same(array('status' => 'succeeded', 'provider_ref' => '4001'), $provider->refund(antwc_refund_row(), 77, '12.34'), 'provider-refund: unsettled full -> void -> succeeded');
$void = Antwc_Fake_Http::last('createTransactionRequest')['payload']['createTransactionRequest'];
antwc_assert_same(array('R77', 'voidTransaction', '4001'), array($void['refId'], $void['transactionRequest']['transactionType'], $void['transactionRequest']['refTransId']), 'provider-refund: void payload');

antwc_refund_setup('capturedPendingSettlement');
antwc_assert_same('antwc_refund_unsettled', antwc_test_code($provider->refund(antwc_refund_row(), 77, '5.00')), 'provider-refund: unsettled partial -> antwc_refund_unsettled');
antwc_assert_same(0, Antwc_Fake_Http::count('createTransactionRequest'), 'provider-refund: unsettled partial sends nothing');
antwc_assert_same('antwc_refund_unsettled', antwc_test_code($provider->refund(antwc_refund_row(array(), '1.00'), 77, '12.34')), 'provider-refund: unsettled full after a prior partial -> antwc_refund_unsettled');

antwc_refund_setup('capturedPendingSettlement');
antwc_refund_answer(array('responseCode' => '3', 'transId' => '0', 'errors' => array(array('errorCode' => '16'))), 'Error');
antwc_assert_same(array('status' => 'failed', 'provider_ref' => null), $provider->refund(antwc_refund_row(), 77, '12.34'), 'provider-refund: refused void -> failed');

antwc_refund_setup('settledSuccessfully');
antwc_refund_answer(array('responseCode' => '1', 'transId' => '5001'));
antwc_assert_same(array('status' => 'succeeded', 'provider_ref' => '5001'), $provider->refund(antwc_refund_row(), 78, '5.00'), 'provider-refund: settled responseCode 1 -> succeeded with the new transId');
$refund = Antwc_Fake_Http::last('createTransactionRequest')['payload']['createTransactionRequest'];
antwc_assert_same(
    array('R78', 'refundTransaction', '5.00', '1111', '4001', 'WCPOS-101'),
    array($refund['refId'], $refund['transactionRequest']['transactionType'], $refund['transactionRequest']['amount'], $refund['transactionRequest']['payment']['creditCard']['cardNumber'], $refund['transactionRequest']['refTransId'], $refund['transactionRequest']['order']['invoiceNumber']),
    'provider-refund: settled refund payload has the last 4 digits and invoice'
);

antwc_refund_setup('settledSuccessfully');
antwc_refund_answer(array('responseCode' => '4', 'transId' => '5002'));
antwc_assert_same(array('status' => 'pending', 'provider_ref' => '5002'), $provider->refund(antwc_refund_row(), 79, '5.00'), 'provider-refund: responseCode 4 -> pending');

antwc_refund_setup('settledSuccessfully');
antwc_refund_answer(array('responseCode' => '2', 'transId' => '0', 'errors' => array(array('errorCode' => '2'))), 'Error');
antwc_assert_same(array('status' => 'failed', 'provider_ref' => null), $provider->refund(antwc_refund_row(), 80, '5.00'), 'provider-refund: responseCode 2 -> failed');
antwc_assert_same(true, count($GLOBALS['antwc_test_log']) > 0, 'provider-refund: failure logged');

antwc_refund_setup('settledSuccessfully');
antwc_refund_answer(array('responseCode' => '3', 'transId' => '0', 'errors' => array(array('errorCode' => '11', 'errorText' => 'A duplicate transaction has been submitted.'))), 'Error');
antwc_assert_same('antwc_refund_duplicate', antwc_test_code($provider->refund(antwc_refund_row(), 81, '5.00')), 'provider-refund: error 11 -> antwc_refund_duplicate');

antwc_refund_setup('settledSuccessfully');
antwc_refund_answer(array('responseCode' => '1', 'transId' => '0'));
$result = $provider->refund(antwc_refund_row(), 82, '5.00');
antwc_assert_same(array('antwc_refund_unknown', true), array(antwc_test_code($result), $result->get_error_data()['indeterminate']), 'provider-refund: approved without a transId -> indeterminate');

antwc_refund_setup('voided');
antwc_assert_same('antwc_refund_voided', antwc_test_code($provider->refund(antwc_refund_row(), 83, '5.00')), 'provider-refund: voided -> error');
antwc_refund_setup('declined');
antwc_assert_same('antwc_refund_unavailable', antwc_test_code($provider->refund(antwc_refund_row(), 83, '5.00')), 'provider-refund: other status -> antwc_refund_unavailable');

antwc_refund_setup('settledSuccessfully');
Antwc_Fake_Http::$failure = 'wp_error';
$result = $provider->refund(antwc_refund_row(), 84, '5.00');
antwc_assert_same(array('antwc_api_unavailable', true), array(antwc_test_code($result), $result->get_error_data()['indeterminate']), 'provider-refund: transport failure -> indeterminate WP_Error');

antwc_refund_setup('settledSuccessfully');
Antwc_Fake_Http::$details['4001'] = antwc_test_details('4001', 12.34, $now);
Antwc_Fake_Http::$details['4001']['transactionStatus'] = 'settledSuccessfully';
Antwc_Fake_Http::$create[] = array('messages' => array('resultCode' => 'Error', 'message' => array(array('code' => 'E00007', 'text' => 'User authentication failed.'))));
$result = $provider->refund(antwc_refund_row(), 85, '5.00');
antwc_assert_same(array('antwc_api_error', false), array(antwc_test_code($result), !empty($result->get_error_data()['indeterminate'])), 'provider-refund: a determinate API refusal -> plain error');

antwc_refund_setup('settledSuccessfully');
antwc_assert_same('antwc_refund_mode_mismatch', antwc_test_code($provider->refund(antwc_refund_row(array('mode' => 'production')), 86, '5.00')), 'provider-refund: mode mismatch -> error');
antwc_assert_same(0, Antwc_Fake_Http::count(), 'provider-refund: mode mismatch sends nothing');
antwc_assert_same('antwc_refund_no_transaction', antwc_test_code($provider->refund(antwc_refund_row(array('transaction_id' => '')), 86, '5.00')), 'provider-refund: no transaction id -> error');
antwc_assert_same('antwc_refund_invalid_amount', antwc_test_code($provider->refund(antwc_refund_row(), 86, '0.00')), 'provider-refund: zero amount -> error');

$map = array(
    'refundSettledSuccessfully' => 'succeeded',
    'refundPendingSettlement' => 'succeeded',
    'declined' => 'failed',
    'voided' => 'failed',
    'generalError' => 'failed',
    'underReview' => 'pending',
);
foreach ($map as $status => $expected) {
    antwc_test_reset();
    Antwc_Fake_Http::$details['5001'] = array('transId' => '5001', 'transactionStatus' => $status);
    antwc_assert_same(array('status' => $expected, 'provider_ref' => '5001'), $provider->fetch_refund(antwc_refund_row(), array('provider_ref' => '5001')), 'provider-refund: fetch_refund mapping ' . $status . ' -> ' . $expected);
}
Antwc_Fake_Http::$failure = 'http_500';
$result = $provider->fetch_refund(antwc_refund_row(), array('provider_ref' => '5001'));
antwc_assert_same(true, $result->get_error_data()['indeterminate'], 'provider-refund: fetch_refund exception -> indeterminate');

antwc_test_reset();
update_option('antwc_last_api_error', array('code' => 'E00007', 'text' => 'User authentication failed.', 'time' => $now));
$gateway = new WC_Payment_Gateway();
$diagnostics = $provider->diagnostics($gateway);
antwc_assert_same(
    array('Environment' => 'sandbox', 'API Login ID' => '••••ogin', 'Transaction key' => 'set', 'Merchant currency' => 'unknown', 'Last API error' => 'E00007 User authentication failed. (2026-11-11 10:00 UTC)', 'log_source' => 'authorize-net-terminal-for-woocommerce'),
    $diagnostics,
    'provider-refund: diagnostics'
);
antwc_assert_same(false, strpos(json_encode($diagnostics), 'test-key'), 'provider-refund: diagnostics never show the key');
antwc_assert_same(
    array('capabilities' => array('tips' => 'none', 'refunds' => array('via' => 'provider', 'partial' => true), 'void' => true), 'provider_data' => array('mode' => 'sandbox')),
    $provider->describe($gateway),
    'provider-refund: describe'
);
