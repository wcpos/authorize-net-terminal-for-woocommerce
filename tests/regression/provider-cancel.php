<?php

$now = ANTWC_TEST_NOW;
$provider = antwc_test_provider();

antwc_test_reset();
$ref = $provider->create_reader_action(antwc_test_row(), 'authorize-net-app')['ref'];
antwc_assert_same('final', $provider->cancel($ref), 'provider-cancel: no claim -> final');
antwc_test_tap('4001', 12.34, $now + 5);
$result = $provider->fetch($ref);
antwc_assert_same(array('cancelled', 0), array($result['status'], Antwc_Fake_Http::count()), 'provider-cancel: then fetch -> cancelled, no HTTP');
antwc_assert_same(array(), $GLOBALS['wpdb']->rows, 'provider-cancel: a cancelled leg never claims');
antwc_assert_same('cancelled', $provider->answer($ref, 'p', 'txn:4001')['status'], 'provider-cancel: answer after cancel -> cancelled');
antwc_assert_same('final', $provider->cancel($ref), 'provider-cancel: cancel again -> final');

antwc_test_reset();
$ref = $provider->create_reader_action(antwc_test_row(), 'authorize-net-app')['ref'];
antwc_test_tap('4001', 12.34, $now + 5);
antwc_assert_same('completed', $provider->fetch($ref)['status'], 'provider-cancel: leg completed first');
$result = $provider->cancel($ref);
antwc_assert_same(array('antwc_cancel_claimed', 409), array(antwc_test_code($result), $result->get_error_data()['status']), 'provider-cancel: claimed -> WP_Error');
antwc_assert_same('completed', $provider->fetch($ref)['status'], 'provider-cancel: claimed leg stays completed');

antwc_test_reset();
antwc_assert_same('final', $provider->cancel('antwc:never-started'), 'provider-cancel: no action record -> final');
antwc_assert_same('wcpos_capture_mode_unsupported', antwc_test_code($provider->capture('antwc:row-a')), 'provider-cancel: capture unsupported');
