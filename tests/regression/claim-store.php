<?php

use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Server\Claim_Store;

antwc_test_reset();
$claims = new Claim_Store();

antwc_assert_same(array('ok' => true), $claims->claim('sandbox', '4001', 'row-a', 101), 'claim-store: first claim ok');
antwc_assert_same(array('ok' => true), $claims->claim('sandbox', '4001', 'row-a', 101), 'claim-store: same row again ok');
antwc_assert_same(array('ok' => false, 'order_id' => 101), $claims->claim('sandbox', '4001', 'row-b', 202), 'claim-store: another row refused with the holder order id');
antwc_assert_same(true, $claims->available_to('sandbox', '4001', 'row-a'), 'claim-store: available to its holder');
antwc_assert_same(false, $claims->available_to('sandbox', '4001', 'row-b'), 'claim-store: not available to another row');
antwc_assert_same(array('ok' => true), $claims->claim('production', '4001', 'row-b', 202), 'claim-store: claims are per mode');

$GLOBALS['antwc_test_orders'] = array(303 => '4002');
antwc_assert_same(array('ok' => false, 'order_id' => 303), $claims->claim('sandbox', '4002', 'row-c', 404), 'claim-store: a wc_get_orders hit refused');
antwc_assert_same(array('ok' => true), $claims->claim('sandbox', '4002', 'row-d', 303), 'claim-store: the order holding the id itself is excluded');

$GLOBALS['wpdb']->fail_next_query = true;
antwc_assert_same(array('ok' => false, 'order_id' => 0), $claims->claim('sandbox', '4003', 'row-e', 505), 'claim-store: a $wpdb->query false refused');
antwc_assert_same(true, $claims->available_to('sandbox', '4003', 'row-x'), 'claim-store: nothing stored after the failed insert');

antwc_assert_same(array('ok' => false, 'order_id' => 0), $claims->claim('test', '4004', 'row-f', 1), 'claim-store: unknown mode refused');
antwc_assert_same(array('ok' => false, 'order_id' => 0), $claims->claim('sandbox', '40x4', 'row-f', 1), 'claim-store: non-numeric id refused');

$GLOBALS['wpdb']->rows['antwc_claim_sandbox_4005'] = 'not json';
antwc_assert_same(false, $claims->available_to('sandbox', '4005', 'row-g'), 'claim-store: an unreadable claim counts as someone else');
antwc_assert_same(array('ok' => false, 'order_id' => 0), $claims->claim('sandbox', '4005', 'row-g', 1), 'claim-store: an unreadable claim refuses');
