<?php

namespace WCPOS\WooCommercePOS\AuthorizeNetTerminal\Server;

/**
 * The single-use guarantee: an Authorize.net transaction backs at most one ledger row, ever.
 *
 * A claim is one options row (antwc_claim_<mode>_<transId>) inserted with INSERT IGNORE on the unique
 * option_name, so of two racing requests exactly one inserts. Claims are never deleted. Reads go to the
 * table directly, past the options cache, and any database doubt refuses.
 */
class Claim_Store
{
    private const PREFIX = 'antwc_claim_';

    /**
     * @return array<string, mixed> ok true, or ok false with the holder's order_id (0 when unknown).
     */
    public function claim(string $mode, string $trans_id, string $row_id, int $order_id): array
    {
        global $wpdb;

        $name = self::option_name($mode, $trans_id);
        if ($name === null || !is_object($wpdb)) {
            return self::refused(0);
        }

        // Recorded some other way (a manual entry, another plugin): never claim it again.
        $orders = wc_get_orders(array('transaction_id' => $trans_id, 'limit' => 1, 'return' => 'ids', 'exclude' => array($order_id)));
        if (!is_array($orders)) {
            return self::refused(0);
        }
        if (count($orders) > 0) {
            return self::refused((int) reset($orders));
        }

        $value = wp_json_encode(array('row_id' => $row_id, 'order_id' => $order_id, 'claimed_at' => time()));
        $inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value));
        if ($inserted === false) {
            return self::refused(0);
        }
        if ((int) $inserted === 1) {
            return array('ok' => true);
        }
        if ((int) $inserted !== 0) {
            return self::refused(0);
        }

        $holder = $this->holder($mode, $trans_id);
        if ($holder !== null && $holder['row_id'] === $row_id) {
            return array('ok' => true); // Our own claim, asked again.
        }

        return self::refused($holder !== null ? (int) $holder['order_id'] : 0);
    }

    /**
     * The stored claim, read from the table, or null when there is none (or it cannot be read).
     *
     * @return array<string, mixed>|null row_id, order_id, claimed_at.
     */
    public function holder(string $mode, string $trans_id): ?array
    {
        $raw = self::raw($mode, $trans_id);
        $holder = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($holder) || !isset($holder['row_id'])) {
            return null;
        }
        $holder['row_id'] = (string) $holder['row_id'];
        $holder['order_id'] = (int) ($holder['order_id'] ?? 0);

        return $holder;
    }

    /**
     * Whether a candidate is offered: unclaimed, or claimed by this row. A claim that exists but cannot
     * be decoded counts as someone else's. Read-only; the claim itself decides.
     */
    public function available_to(string $mode, string $trans_id, string $row_id): bool
    {
        if (self::option_name($mode, $trans_id) === null) {
            return false;
        }
        if (self::raw($mode, $trans_id) === null) {
            return true;
        }
        $holder = $this->holder($mode, $trans_id);

        return $holder !== null && $holder['row_id'] === $row_id;
    }

    /**
     * @return mixed The stored option_value, or null.
     */
    private static function raw(string $mode, string $trans_id)
    {
        global $wpdb;

        $name = self::option_name($mode, $trans_id);
        if ($name === null || !is_object($wpdb)) {
            return null;
        }

        return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    }

    private static function option_name(string $mode, string $trans_id): ?string
    {
        if (!in_array($mode, array('sandbox', 'production'), true) || preg_match('/^\d{1,20}$/', $trans_id) !== 1) {
            return null;
        }

        return self::PREFIX . $mode . '_' . $trans_id;
    }

    /**
     * @return array<string, mixed>
     */
    private static function refused(int $order_id): array
    {
        return array('ok' => false, 'order_id' => $order_id);
    }
}
