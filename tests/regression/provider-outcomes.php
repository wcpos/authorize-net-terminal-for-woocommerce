<?php

$now = ANTWC_TEST_NOW;
$provider = antwc_test_provider();

/** A list-only decline: in the unsettled list, as Authorize.net lists one. */
function antwc_test_decline(string $trans_id, float $amount, int $submitted): void
{
    Antwc_Fake_Http::$unsettled[] = array_merge(antwc_test_details($trans_id, $amount, $submitted), array('transactionStatus' => 'declined', 'responseCode' => 2));
}

// Declines.
$ref = antwc_fetch_setup();
antwc_test_decline('4001', 12.34, $now + 5);
$result = $provider->fetch($ref);
antwc_assert_same(array('failed', 'declined', null, null), array($result['status'], $result['failure_reason'], $result['amount'], $result['currency']), 'provider-decline: a fresh same-amount decline, nothing captured -> failed/declined');
antwc_assert_same(array(array(), 0), array($GLOBALS['wpdb']->rows, Antwc_Fake_Http::count('getTransactionDetailsRequest')), 'provider-decline: a decline claims and verifies nothing');

$ref = antwc_fetch_setup();
antwc_test_decline('4001', 12.34, $now - 30);
antwc_assert_same('failed', $provider->fetch($ref)['status'], 'provider-decline: a decline at created_at - skew still fails');

$ref = antwc_fetch_setup(array('auto_match' => 'no'));
antwc_test_decline('4001', 12.34, $now + 5);
antwc_test_tap('4002', 12.34, $now - 120);
$result = $provider->fetch($ref);
antwc_assert_same(array('in_progress', array('txn:4002')), array($result['status'], array_column($result['prompt']['buttons'], 'id')), 'provider-decline: a decline plus an older captured candidate -> not failed');

$ref = antwc_fetch_setup();
antwc_test_decline('4001', 12.34, $now + 5);
antwc_test_tap('4002', 12.34, $now + 9);
antwc_assert_same(array('completed', '4002'), array($provider->fetch($ref)['status'], $provider->fetch($ref)['provider_refs']['transaction_id']), 'provider-decline: a decline plus a fresh captured candidate -> completed');

$ref = antwc_fetch_setup();
antwc_test_decline('4001', 12.34, $now - 31);
antwc_assert_same('in_progress', $provider->fetch($ref)['status'], 'provider-decline: a stale decline (before created_at - skew) -> not failed');

$ref = antwc_fetch_setup();
antwc_test_decline('4001', 12.35, $now + 5);
antwc_assert_same('in_progress', $provider->fetch($ref)['status'], 'provider-decline: a decline for a different amount -> not failed');

// Mismatches: what Authorize.net reports, never the row's money, and never claimed.
$ref = antwc_fetch_setup();
antwc_test_tap('4001', 12.34, $now + 5, array('settleAmount' => 10.0));
$result = $provider->fetch($ref);
antwc_assert_same(array('completed', '10.00', 'USD', '4001'), array($result['status'], $result['amount'], $result['currency'], $result['provider_refs']['transaction_id']), 'provider-mismatch: details settleAmount differs -> completed with the details amount');
antwc_assert_same(array(array(), null), array($GLOBALS['wpdb']->rows, (new WCPOS\WooCommercePOS\AuthorizeNetTerminal\Server\Action_Store())->get('row-a')['claim']), 'provider-mismatch: amount mismatch -> no claim row, no stored claim');
antwc_assert_same(true, $result['amount'] !== '12.34', 'provider-mismatch: the reported amount never equals the row amount when the details differ');
antwc_assert_same('warning', end($GLOBALS['antwc_test_log'])['level'] ?? 'none', 'provider-mismatch: the mismatch is logged as a warning');

$ref = antwc_fetch_setup();
antwc_test_tap('4001', 12.34, $now + 5, array('settleAmount' => 12.35));
$result = $provider->fetch($ref);
antwc_assert_same(array('completed', '12.35', 'USD'), array($result['status'], $result['amount'], $result['currency']), 'provider-mismatch: an overpayment is reported as taken');

$ref = antwc_fetch_setup();
Antwc_Fake_Http::$currencies = array('EUR', 'GBP');
antwc_test_tap('4001', 12.34, $now + 5);
$result = $provider->fetch($ref);
antwc_assert_same(array('completed', '12.34', 'EUR', '4001'), array($result['status'], $result['amount'], $result['currency'], $result['provider_refs']['transaction_id']), 'provider-mismatch: currency not in the merchant list -> completed with the first merchant currency');
antwc_assert_same(array(), $GLOBALS['wpdb']->rows, 'provider-mismatch: currency mismatch -> no claim');

$ref = antwc_fetch_setup();
Antwc_Fake_Http::$currencies = array('EUR', 'USD');
antwc_test_tap('4001', 12.34, $now + 5, array('settleAmount' => 9.5));
$result = $provider->fetch($ref);
antwc_assert_same(array('completed', '9.50', 'USD'), array($result['status'], $result['amount'], $result['currency']), 'provider-mismatch: amount mismatch keeps the order currency when the merchant lists it');

$ref = antwc_fetch_setup();
antwc_test_tap('4001', 12.34, $now + 5, array('settleAmount' => 'n/a'));
$result = $provider->fetch($ref);
antwc_assert_same(array('in_progress', 'That Authorize.net payment is not for USD 12.34.'), array($result['status'], $result['prompt']['lines'][0]), 'provider-mismatch: an unreadable settleAmount stays a refusal');

$ref = antwc_fetch_setup();
Antwc_Fake_Http::$currencies = array();
antwc_test_tap('4001', 12.34, $now + 5);
antwc_assert_same('in_progress', $provider->fetch($ref)['status'], 'provider-mismatch: no merchant currency known stays a refusal');

antwc_answer_setup();
antwc_test_tap('4001', 12.34, $now + 5, array('settleAmount' => 1.0, 'authAmount' => 1.0));
$result = $provider->answer('antwc:row-a', 'p', 'txn:4001');
antwc_assert_same(array('completed', '1.00', 'USD', array()), array($result['status'], $result['amount'], $result['currency'], $GLOBALS['wpdb']->rows), 'provider-mismatch: a picked transaction with the wrong amount -> completed with its amount, no claim');

$ref = antwc_cancel_setup();
antwc_test_tap('4001', 12.34, $now + 5, array('settleAmount' => 10.0));
$result = $provider->fetch($ref);
antwc_assert_same(array('completed', '10.00', array()), array($result['status'], $result['amount'], $GLOBALS['wpdb']->rows), 'provider-mismatch: on a cancel-requested leg the wrong money is still reported, unclaimed');

foreach (array('not_found' => array('transId' => '9999'), 'wrong_type' => array('transactionType' => 'refundTransaction'), 'not_captured' => array('responseCode' => 2), 'too_old' => array('submitTimeUTC' => antwc_test_time($now - 901)), 'in_future' => array('submitTimeUTC' => antwc_test_time($now + 301))) as $reason => $override) {
    $ref = antwc_fetch_setup();
    antwc_test_tap('4001', 12.34, $now + 5, $override);
    $result = $provider->fetch($ref);
    antwc_assert_same(array('in_progress', array()), array($result['status'], $GLOBALS['wpdb']->rows), 'provider-mismatch: refusal ' . $reason . ' stays in_progress');
}
