<?php

$now = ANTWC_TEST_NOW;

/**
 * A fresh leg for row-a (USD 12.34, order 101) created at the test clock.
 */
function antwc_fetch_setup(array $settings = array()): string
{
    antwc_test_reset($settings);
    $created = antwc_test_provider()->create_reader_action(antwc_test_row(), 'authorize-net-app');

    return $created['ref'];
}

$provider = antwc_test_provider();

antwc_test_reset();
antwc_assert_same('authorize_net', $provider->provider(), 'provider-fetch: provider id');
antwc_assert_same(array(array('id' => 'authorize-net-app', 'label' => 'Front till', 'status' => 'online')), $provider->list_readers(), 'provider-fetch: one reader');
antwc_assert_same(array('ref' => 'antwc:row-a', 'expires_at' => null), $provider->create_reader_action(antwc_test_row(), 'authorize-net-app'), 'provider-fetch: create returns the ref');
antwc_assert_same(0, Antwc_Fake_Http::count(), 'provider-fetch: create makes no HTTP call');
antwc_assert_same('antwc_action_conflict', antwc_test_code($provider->create_reader_action(antwc_test_row('row-a', '99.00'), 'authorize-net-app')), 'provider-fetch: same row, other amount refused');
antwc_assert_same('antwc_unknown_action', antwc_test_code($provider->fetch('antwc:nope')), 'provider-fetch: unknown ref');
antwc_test_reset(array('transaction_key' => ''));
antwc_assert_same('antwc_not_configured', antwc_test_code($provider->list_readers()), 'provider-fetch: no credentials, no readers');

$ref = antwc_fetch_setup();
$result = $provider->fetch($ref);
antwc_assert_same(array('in_progress', array()), array($result['status'], $result['prompt']['buttons']), 'provider-fetch: no candidates -> in_progress, empty buttons');
antwc_assert_same(array('Take USD 12.34 in the Authorize.net app. This completes by itself when the payment arrives.'), $result['prompt']['lines'], 'provider-fetch: no candidates line');
antwc_assert_same(array(null, null), array($result['amount'], $result['currency']), 'provider-fetch: in_progress carries no amount');

$ref = antwc_fetch_setup();
antwc_test_tap('4001', 12.34, $now + 5);
$result = $provider->fetch($ref);
antwc_assert_same('completed', $result['status'], 'provider-fetch: one fresh candidate + auto_match -> completed');
antwc_assert_same(array('transaction_id' => '4001', 'mode' => 'sandbox', 'card_last4' => '1111'), $result['provider_refs'], 'provider-fetch: provider_refs.transaction_id');
antwc_assert_same(array('12.34', 'USD'), array($result['amount'], $result['currency']), 'provider-fetch: completed with the row amount');
antwc_assert_same(array('card_label' => 'Visa', 'card_last4' => '1111', 'auth_code' => 'ABC123', 'read_method' => 'authorize_net_app'), $result['receipt'], 'provider-fetch: receipt');
antwc_assert_same(true, isset($GLOBALS['wpdb']->rows['antwc_claim_sandbox_4001']), 'provider-fetch: auto-match claimed the transaction');

$ref = antwc_fetch_setup(array('auto_match' => 'no'));
antwc_test_tap('4001', 12.34, $now + 5);
$result = $provider->fetch($ref);
antwc_assert_same(array('in_progress', 1), array($result['status'], count($result['prompt']['buttons'])), 'provider-fetch: one fresh candidate + auto_match off -> prompt with one button');
antwc_assert_same(array('id' => 'txn:4001', 'label' => 'Visa ••1111 · 10:00:05 · #4001'), $result['prompt']['buttons'][0], 'provider-fetch: button id and label');
antwc_assert_same('1 Authorize.net payment of USD 12.34 could be this one. Pick the one you just took.', $result['prompt']['lines'][0], 'provider-fetch: one candidate line');
antwc_assert_same(array(), $GLOBALS['wpdb']->rows, 'provider-fetch: auto_match off claims nothing');

$ref = antwc_fetch_setup();
antwc_test_tap('4001', 12.34, $now + 5);
antwc_test_tap('4002', 12.34, $now + 9);
$result = $provider->fetch($ref);
antwc_assert_same(array('in_progress', array('txn:4002', 'txn:4001')), array($result['status'], array_column($result['prompt']['buttons'], 'id')), 'provider-fetch: two same-amount candidates -> two buttons, newest first');
antwc_assert_same('2 Authorize.net payments of USD 12.34 could be this one. Pick the one you just took.', $result['prompt']['lines'][0], 'provider-fetch: two candidates line');
antwc_assert_same(array(), $GLOBALS['wpdb']->rows, 'provider-fetch: two candidates, no claim');
antwc_test_expire_list();
$again = $provider->fetch($ref);
antwc_assert_same($result['prompt']['id'], $again['prompt']['id'], 'provider-fetch: stable prompt id across two identical fetches');
antwc_assert_same(2, Antwc_Fake_Http::count('getUnsettledTransactionListRequest'), 'provider-fetch: second fetch re-read the list');
antwc_test_tap('4003', 12.34, $now + 12);
antwc_test_expire_list();
antwc_assert_same(true, $provider->fetch($ref)['prompt']['id'] !== $result['prompt']['id'], 'provider-fetch: prompt id changes with the list');
$provider->fetch($ref);
antwc_assert_same(3, Antwc_Fake_Http::count('getUnsettledTransactionListRequest'), 'provider-fetch: list cached between polls');

$ref = antwc_fetch_setup();
antwc_test_tap('4001', 12.34, $now - 31);
$result = $provider->fetch($ref);
antwc_assert_same(array('in_progress', array('txn:4001')), array($result['status'], array_column($result['prompt']['buttons'], 'id')), 'provider-fetch: a candidate older than created_at-30 s -> prompt, not auto');
antwc_assert_same(0, Antwc_Fake_Http::count('getTransactionDetailsRequest'), 'provider-fetch: older candidate not verified');

$ref = antwc_fetch_setup();
antwc_test_tap('4001', 12.34, $now - 901);
antwc_assert_same(array(), $provider->fetch($ref)['prompt']['buttons'], 'provider-fetch: a candidate before the lookback is not offered');

$ref = antwc_fetch_setup();
antwc_test_tap('4001', 12.34, $now + 5);
$GLOBALS['wpdb']->rows['antwc_claim_sandbox_4001'] = json_encode(array('row_id' => 'row-z', 'order_id' => 999, 'claimed_at' => $now));
$result = $provider->fetch($ref);
antwc_assert_same(array('in_progress', array()), array($result['status'], $result['prompt']['buttons']), 'provider-fetch: a candidate claimed by another row -> not offered');

$ref = antwc_fetch_setup();
antwc_test_tap('4001', 12.35, $now + 5);
$result = $provider->fetch($ref);
antwc_assert_same(array('in_progress', array()), array($result['status'], $result['prompt']['buttons']), 'provider-fetch: a different amount -> not offered');

$ref = antwc_fetch_setup();
Antwc_Fake_Http::$unsettled[] = array_merge(antwc_test_details('4001', 12.34, $now + 5), array('transactionStatus' => 'voided'));
antwc_assert_same(array(), $provider->fetch($ref)['prompt']['buttons'], 'provider-fetch: a voided transaction is not offered');

$ref = antwc_fetch_setup();
antwc_test_tap('4001', 12.34, $now + 5, array('transactionType' => 'refundTransaction'));
$result = $provider->fetch($ref);
antwc_assert_same(array('in_progress', array('txn:4001')), array($result['status'], array_column($result['prompt']['buttons'], 'id')), 'provider-fetch: auto-match refusal falls through to the prompt');
antwc_assert_same('That Authorize.net transaction is not a completed sale.', $result['prompt']['lines'][0], 'provider-fetch: refusal line shown');
antwc_assert_same(array(), $GLOBALS['wpdb']->rows, 'provider-fetch: refused auto-match claims nothing');

$ref = antwc_fetch_setup();
$GLOBALS['antwc_test_options']['woocommerce_authorize_net_terminal_for_woocommerce_settings']['environment'] = 'production';
$result = $provider->fetch($ref);
antwc_assert_same(array('failed', 'mode_changed'), array($result['status'], $result['failure_reason']), 'provider-fetch: mode changed -> failed');

$ref = antwc_fetch_setup();
Antwc_Fake_Http::$failure = 'wp_error';
$result = $provider->fetch($ref);
antwc_assert_same(array('antwc_api_unavailable', true), array(antwc_test_code($result), $result->get_error_data()['indeterminate']), 'provider-fetch: API exception -> WP_Error with indeterminate data');

$ref = antwc_fetch_setup();
Antwc_Fake_Http::$raw = json_encode(array('messages' => array('resultCode' => 'Error', 'message' => array(array('code' => 'E00011', 'text' => 'Access denied.')))));
$result = $provider->fetch($ref);
antwc_assert_same('Turn on the Transaction Details API in the Authorize.net Merchant Interface (Account → Settings → Transaction Details API).', $result->get_error_message(), 'provider-fetch: E00011 message');

$ref = antwc_fetch_setup();
antwc_test_tap('4001', 12.34, $now + 5);
$provider->fetch($ref);
Antwc_Fake_Http::reset();
$result = $provider->fetch($ref);
antwc_assert_same(array('completed', '4001', 0), array($result['status'], $result['provider_refs']['transaction_id'], Antwc_Fake_Http::count()), 'provider-fetch: an existing claim -> completed with zero HTTP calls');

// Crash between Claim_Store::claim and set_claim: the next fetch verifies and completes with our claim.
$ref = antwc_fetch_setup(array('auto_match' => 'no'));
antwc_test_tap('4001', 12.34, $now - 120);
$GLOBALS['wpdb']->rows['antwc_claim_sandbox_4001'] = json_encode(array('row_id' => 'row-a', 'order_id' => 101, 'claimed_at' => $now));
$result = $provider->fetch($ref);
antwc_assert_same(array('completed', '4001'), array($result['status'], $result['provider_refs']['transaction_id']), 'provider-fetch: our own unstored claim is recovered');
