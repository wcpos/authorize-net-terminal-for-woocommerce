<?php

use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Server\Action_Store;

$now = ANTWC_TEST_NOW;
$provider = antwc_test_provider();
$store = new Action_Store();

/** A fresh leg for row-a (USD 12.34, order 101), cancel requested at the test clock. */
function antwc_cancel_setup(array $settings = array()): string
{
    antwc_test_reset($settings);
    $ref = antwc_test_provider()->create_reader_action(antwc_test_row(), 'authorize-net-app')['ref'];
    antwc_assert_same('requested', antwc_test_provider()->cancel($ref), 'provider-cancel: setup cancel -> requested');

    return $ref;
}

antwc_test_reset();
$ref = $provider->create_reader_action(antwc_test_row(), 'authorize-net-app')['ref'];
antwc_assert_same('requested', $provider->cancel($ref), 'provider-cancel: no claim -> requested');
antwc_assert_same(array($now, false, 0), array($store->get('row-a')['cancel_requested_at'], $store->get('row-a')['cancelled'], Antwc_Fake_Http::count()), 'provider-cancel: cancel_requested_at stored, nothing cancelled yet, no HTTP');
antwc_assert_same('requested', antwc_test_provider($now + 50)->cancel($ref), 'provider-cancel: cancel again -> requested');
antwc_assert_same($now, $store->get('row-a')['cancel_requested_at'], 'provider-cancel: the first request time is kept');
$answer = $provider->answer($ref, 'p', 'txn:4001');
antwc_assert_same(array('antwc_cancel_requested', 409, 0), array(antwc_test_code($answer), $answer->get_error_data()['status'], Antwc_Fake_Http::count()), 'provider-cancel: answer on a cancel-requested leg -> 409, no HTTP');

antwc_test_reset();
$ref = $provider->create_reader_action(antwc_test_row(), 'authorize-net-app')['ref'];
antwc_test_tap('4001', 12.34, $now + 5);
antwc_assert_same('completed', $provider->fetch($ref)['status'], 'provider-cancel: leg completed first');
$result = $provider->cancel($ref);
antwc_assert_same(array('antwc_cancel_claimed', 409), array(antwc_test_code($result), $result->get_error_data()['status']), 'provider-cancel: claimed -> 409');
antwc_assert_same(array('completed', null), array($provider->fetch($ref)['status'], $store->get('row-a')['cancel_requested_at']), 'provider-cancel: claimed leg stays completed, no cancel stored');

antwc_test_reset();
antwc_assert_same('final', $provider->cancel('antwc:never-started'), 'provider-cancel: no action record -> final');
antwc_assert_same('wcpos_capture_mode_unsupported', antwc_test_code($provider->capture('antwc:row-a')), 'provider-cancel: capture unsupported');

// The fetch after a requested cancel.
$ref = antwc_cancel_setup();
antwc_test_tap('4001', 12.34, $now + 5);
$result = $provider->fetch($ref);
antwc_assert_same(array('completed', '4001', '12.34'), array($result['status'], $result['provider_refs']['transaction_id'], $result['amount']), 'provider-cancel: requested + one fresh -> completed');
antwc_assert_same(array(true, '4001'), array(isset($GLOBALS['wpdb']->rows['antwc_claim_sandbox_4001']), $store->get('row-a')['claim']['trans_id']), 'provider-cancel: requested + one fresh -> claim written');

$ref = antwc_cancel_setup();
antwc_test_tap('4001', 12.34, $now + 5);
antwc_test_tap('4002', 12.34, $now + 9);
$result = $provider->fetch($ref);
antwc_assert_same(array('cancelled', 'cancelled', false), array($result['status'], $result['failure_reason'], isset($result['prompt'])), 'provider-cancel: requested + two fresh -> cancelled, no prompt');
antwc_assert_same(array(array(), true), array($GLOBALS['wpdb']->rows, $store->get('row-a')['cancelled']), 'provider-cancel: requested + two fresh -> no claim, cancel stored');
antwc_test_expire_list();
antwc_assert_same(array('cancelled', 1), array($provider->fetch($ref)['status'], Antwc_Fake_Http::count('getUnsettledTransactionListRequest')), 'provider-cancel: a cancelled leg answers without HTTP');

$ref = antwc_cancel_setup();
$result = $provider->fetch($ref);
antwc_assert_same(array('cancelled', false, true), array($result['status'], isset($result['prompt']), $store->get('row-a')['cancelled']), 'provider-cancel: requested + none -> cancelled');

$ref = antwc_cancel_setup();
antwc_test_tap('4001', 12.34, $now - 120);
$result = $provider->fetch($ref);
antwc_assert_same(array('cancelled', false, array()), array($result['status'], isset($result['prompt']), $GLOBALS['wpdb']->rows), 'provider-cancel: requested + only an older candidate -> cancelled, no prompt');

$ref = antwc_cancel_setup(array('auto_match' => 'no'));
antwc_test_tap('4001', 12.34, $now + 5);
$result = $provider->fetch($ref);
antwc_assert_same(array('completed', '4001'), array($result['status'], $result['provider_refs']['transaction_id']), 'provider-cancel: requested + auto_match off + one fresh -> completed');

$ref = antwc_cancel_setup();
antwc_test_tap('4001', 12.34, $now + 5, array('transactionStatus' => 'declined', 'responseCode' => 2));
$result = $provider->fetch($ref);
antwc_assert_same(array('cancelled', false, array()), array($result['status'], isset($result['prompt']), $GLOBALS['wpdb']->rows), 'provider-cancel: requested + verifier refusal -> cancelled, no prompt');

$ref = antwc_cancel_setup();
Antwc_Fake_Http::$unsettled[] = array_merge(antwc_test_details('4001', 12.34, $now + 5), array('transactionStatus' => 'declined'));
antwc_assert_same('cancelled', $provider->fetch($ref)['status'], 'provider-cancel: requested + a decline -> cancelled');

foreach (array('wp_error', 'http_500') as $failure) {
    $ref = antwc_cancel_setup();
    antwc_test_tap('4001', 12.34, $now + 5);
    Antwc_Fake_Http::$failure = $failure;
    $result = $provider->fetch($ref);
    antwc_assert_same(array('antwc_api_unavailable', true), array(antwc_test_code($result), $result->get_error_data()['indeterminate']), 'provider-cancel: requested + ' . $failure . ' -> indeterminate');
    antwc_assert_same(array(false, $now, array()), array($store->get('row-a')['cancelled'], $store->get('row-a')['cancel_requested_at'], $GLOBALS['wpdb']->rows), 'provider-cancel: requested + ' . $failure . ' -> no cancel stored, no claim');
}

// A doubt about a listed tap is not a no: the cancel stays undecided.
$ref = antwc_cancel_setup();
antwc_test_tap('4001', 12.34, $now + 5);
Antwc_Fake_Http::$details['4001']['transId'] = '9999'; // Details for another transaction: not_found.
$GLOBALS['wpdb']->rows['antwc_claim_sandbox_4001'] = json_encode(array('row_id' => 'row-a', 'order_id' => 101, 'claimed_at' => $now));
$result = $provider->fetch($ref);
antwc_assert_same(array('antwc_claim_unverified', true, false), array(antwc_test_code($result), $result->get_error_data()['indeterminate'], $store->get('row-a')['cancelled']), 'provider-cancel: requested + ours whose details refuse -> indeterminate, not cancelled');

$ref = antwc_cancel_setup();
antwc_test_tap('4001', 12.34, $now + 5);
$details = Antwc_Fake_Http::$details['4001'];
Antwc_Fake_Http::$details['4001']['transId'] = '9999'; // Details for another transaction: not_found.
$result = $provider->fetch($ref);
antwc_assert_same(array('antwc_cancel_undecided', true, false, array()), array(antwc_test_code($result), $result->get_error_data()['indeterminate'], $store->get('row-a')['cancelled'], $GLOBALS['wpdb']->rows), 'provider-cancel: requested + one fresh not_found -> indeterminate, not cancelled');
Antwc_Fake_Http::$details['4001'] = $details;
$result = $provider->fetch($ref);
antwc_assert_same(array('completed', '4001'), array($result['status'], $result['provider_refs']['transaction_id']), 'provider-cancel: the next fetch with the details found -> completed');

$ref = antwc_cancel_setup();
antwc_test_tap('4001', 12.34, $now + 5, array('submitTimeUTC' => antwc_test_time($now + 3600)));
$result = $provider->fetch($ref);
antwc_assert_same(array('antwc_cancel_undecided', false), array(antwc_test_code($result), $store->get('row-a')['cancelled']), 'provider-cancel: requested + one fresh in_future -> indeterminate, not cancelled');

$ref = antwc_cancel_setup();
antwc_test_tap('4001', 12.34, $now + 5, array('transactionStatus' => 'voided'));
$result = $provider->fetch($ref);
antwc_assert_same(array('cancelled', true), array($result['status'], $store->get('row-a')['cancelled']), 'provider-cancel: requested + one fresh not_captured -> cancelled');
