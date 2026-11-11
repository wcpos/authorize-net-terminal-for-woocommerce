<?php

$now = ANTWC_TEST_NOW;
$provider = antwc_test_provider();

/**
 * Legs for row-a (order 101) and row-b (order 202), both USD 12.34, with auto-match off.
 */
function antwc_answer_setup(): void
{
    antwc_test_reset(array('auto_match' => 'no'));
    antwc_test_provider()->create_reader_action(antwc_test_row('row-a', '12.34', 101), 'authorize-net-app');
    antwc_test_provider()->create_reader_action(antwc_test_row('row-b', '12.34', 202), 'authorize-net-app');
}

antwc_answer_setup();
antwc_test_tap('4001', 12.34, $now + 5);
$prompt = $provider->fetch('antwc:row-a')['prompt'];
$result = $provider->answer('antwc:row-a', $prompt['id'], 'txn:4001');
antwc_assert_same(array('completed', '4001'), array($result['status'], $result['provider_refs']['transaction_id']), 'provider-answer: valid pick -> completed');
Antwc_Fake_Http::reset();
$later = $provider->fetch('antwc:row-a');
antwc_assert_same(array('completed', '4001', 0), array($later['status'], $later['provider_refs']['transaction_id'], Antwc_Fake_Http::count()), 'provider-answer: later fetch completed with zero HTTP calls');
antwc_assert_same($later, $provider->answer('antwc:row-a', $prompt['id'], 'txn:4001'), 'provider-answer: answering again reads the stored claim');

antwc_answer_setup();
antwc_test_tap('4001', 12.34, $now + 5);
$provider->answer('antwc:row-a', 'p', 'txn:4001');
$result = $provider->answer('antwc:row-b', 'p', 'txn:4001');
antwc_assert_same('in_progress', $result['status'], 'provider-answer: a pick another row claimed -> in_progress');
antwc_assert_same('That payment is already recorded on order #101.', $result['prompt']['lines'][0], 'provider-answer: "already recorded on order #N" line');
antwc_assert_same(array(), $result['prompt']['buttons'], 'provider-answer: the claimed transaction is not offered again');

antwc_answer_setup();
antwc_test_tap('4001', 12.34, $now + 5);
$GLOBALS['antwc_test_orders'] = array(303 => '4001');
$result = $provider->answer('antwc:row-a', 'p', 'txn:4001');
antwc_assert_same(array('in_progress', 'That payment is already recorded on order #303.'), array($result['status'], $result['prompt']['lines'][0]), 'provider-answer: a transaction already on another order is refused');

antwc_answer_setup();
antwc_test_tap('4001', 12.34, $now + 5, array('submitTimeUTC' => antwc_test_time($now - 901)));
$result = $provider->answer('antwc:row-a', 'p', 'txn:4001');
antwc_assert_same(array('in_progress', 'That Authorize.net payment was taken before this sale started.'), array($result['status'], $result['prompt']['lines'][0]), 'provider-answer: a pick too old by its details (details differ from list) -> in_progress');
antwc_assert_same(array(), $GLOBALS['wpdb']->rows, 'provider-answer: too old, no claim');
antwc_assert_same(array('txn:4001'), array_column($result['prompt']['buttons'], 'id'), 'provider-answer: refusal prompt lists current candidates');

foreach (array('declined', 'voided') as $status) {
    antwc_answer_setup();
    antwc_test_tap('4001', 12.34, $now + 5, array('transactionStatus' => $status, 'responseCode' => 2));
    $result = $provider->answer('antwc:row-a', 'p', 'txn:4001');
    antwc_assert_same(array('in_progress', array()), array($result['status'], $GLOBALS['wpdb']->rows), 'provider-answer: a ' . $status . ' pick -> in_progress, no claim');
}

antwc_answer_setup();
Antwc_Fake_Http::$details['4009'] = array_merge(antwc_test_details('4008', 12.34, $now + 5));
$result = $provider->answer('antwc:row-a', 'p', 'txn:4009');
antwc_assert_same(array('in_progress', array()), array($result['status'], $GLOBALS['wpdb']->rows), 'provider-answer: details for another transId -> in_progress');

antwc_answer_setup();
antwc_assert_same('antwc_bad_button', antwc_test_code($provider->answer('antwc:row-a', 'p', 'cancel')), 'provider-answer: a bad button id -> WP_Error');
antwc_assert_same('antwc_bad_button', antwc_test_code($provider->answer('antwc:row-a', 'p', 'txn:12a')), 'provider-answer: a non-numeric transId -> WP_Error');
antwc_assert_same('antwc_unknown_action', antwc_test_code($provider->answer('antwc:row-q', 'p', 'txn:1')), 'provider-answer: unknown ref -> WP_Error');

antwc_answer_setup();
antwc_test_tap('4001', 12.34, $now + 5);
Antwc_Fake_Http::$failure = 'http_500';
$result = $provider->answer('antwc:row-a', 'p', 'txn:4001');
antwc_assert_same(array('antwc_api_unavailable', true, array()), array(antwc_test_code($result), $result->get_error_data()['indeterminate'], $GLOBALS['wpdb']->rows), 'provider-answer: API failure -> indeterminate, no claim');

// Double claim race: row-b's whole answer runs inside row-a's INSERT, before it lands.
antwc_answer_setup();
antwc_test_tap('4001', 12.34, $now + 5);
$inner = null;
$GLOBALS['wpdb']->before_insert = static function () use ($provider, &$inner) {
    $inner = $provider->answer('antwc:row-b', 'p', 'txn:4001');
};
$outer = $provider->answer('antwc:row-a', 'p', 'txn:4001');
$completed = 0;
foreach (array($outer, $inner) as $answer) {
    $completed += is_array($answer) && $answer['status'] === 'completed' ? 1 : 0;
}
antwc_assert_same(1, $completed, 'provider-answer: double claim race -> exactly one completed');
antwc_assert_same(array('completed', 'in_progress'), array($inner['status'], $outer['status']), 'provider-answer: double claim race -> the first insert wins, the other stays in_progress');
antwc_assert_same('That payment is already recorded on order #202.', $outer['prompt']['lines'][0], 'provider-answer: double claim race -> loser told the holder');
$a = $provider->fetch('antwc:row-a');
$b = $provider->fetch('antwc:row-b');
antwc_assert_same(array('in_progress', 'completed'), array($a['status'], $b['status']), 'provider-answer: double claim race -> later fetches agree');
