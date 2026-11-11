<?php

use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Services\TransactionVerifier;

$verifier = new TransactionVerifier();
$now = ANTWC_TEST_NOW;
$good = antwc_test_details('4001', 12.34, $now - 60);

/**
 * @param array<string, mixed> $result
 */
function antwc_reason(array $result): string
{
    return empty($result['ok']) ? (string) $result['reason'] : 'ok';
}

$ok = $verifier->verify($good, '12.34', 'usd', array('USD'), $now - 900, $now);
antwc_assert_same(true, $ok['ok'], 'verifier: a captured sale passes');
antwc_assert_same(
    array('4001', '12.34', 'USD', 'Visa', '1111', 'ABC123', $now - 60),
    array($ok['trans_id'], $ok['amount'], $ok['currency'], $ok['card_type'], $ok['last4'], $ok['auth_code'], $ok['submitted_at']),
    'verifier: verified fields'
);

// Each case also breaks every later check, so the code proves the order.
$late = array('submitTimeUTC' => 'garbage', 'settleAmount' => 99.0);
$cases = array(
    'not_found' => array(array(), array('USD')),
    'wrong_type' => array(array_merge($good, $late, array('transactionType' => 'refundTransaction', 'transactionStatus' => 'declined')), array()),
    'not_captured' => array(array_merge($good, $late, array('transactionStatus' => 'declined')), array()),
    'currency_mismatch' => array(array_merge($good, $late), array('EUR')),
    'amount_mismatch' => array(array_merge($good, $late), array('USD')),
    'too_old' => array(array_merge($good, array('submitTimeUTC' => antwc_test_time($now - 901))), array('USD')),
    'in_future' => array(array_merge($good, array('submitTimeUTC' => antwc_test_time($now + 301))), array('USD')),
);
foreach ($cases as $code => $case) {
    antwc_assert_same($code, antwc_reason($verifier->verify($case[0], '12.34', 'USD', $case[1], $now - 900, $now)), 'verifier: refusal ' . $code . ' in order');
}
antwc_assert_same('not_captured', antwc_reason($verifier->verify(array_merge($good, array('responseCode' => 2)), '12.34', 'USD', array('USD'), $now - 900, $now)), 'verifier: responseCode 2 is not captured');
antwc_assert_same('currency_mismatch', antwc_reason($verifier->verify($good, '12.34', 'USD', array(), $now - 900, $now)), 'verifier: empty merchant currencies refused');
antwc_assert_same('too_old', antwc_reason($verifier->verify(array_merge($good, array('submitTimeUTC' => '2026-11-11T09:59:00+05:00')), '12.34', 'USD', array('USD'), $now - 900, $now)), 'verifier: non-UTC time is a parse failure');
antwc_assert_same('ok', antwc_reason($verifier->verify(array_merge($good, array('settleAmount' => 12.3)), '12.30', 'USD', array('USD'), $now - 900, $now)), 'verifier: float 12.3 equals "12.30"');
antwc_assert_same('amount_mismatch', antwc_reason($verifier->verify($good, '12.35', 'USD', array('USD'), $now - 900, $now)), 'verifier: 12.34 vs "12.35" refused');
antwc_assert_same('ok', antwc_reason($verifier->verify(array_merge($good, array('settleAmount' => 1500)), '1500', 'JPY', array('JPY'), $now - 900, $now)), 'verifier: 0-dp JPY 1500 matches');
antwc_assert_same('amount_mismatch', antwc_reason($verifier->verify(array_merge($good, array('settleAmount' => 1500)), '1501', 'JPY', array('JPY'), $now - 900, $now)), 'verifier: 0-dp JPY 1500 vs 1501 refused');
antwc_assert_same('amount_mismatch', antwc_reason($verifier->verify(array_merge($good, array('settleAmount' => 0)), '0.00', 'USD', array('USD'), $now - 900, $now)), 'verifier: zero amount refused');
antwc_assert_same($now - 60, TransactionVerifier::parse_utc(gmdate('Y-m-d\TH:i:s', $now - 60)), 'verifier: suffix-less submitTimeUTC read as UTC');
