<?php

namespace WCPOS\WooCommercePOS\AuthorizeNetTerminal\Server;

/**
 * One record per ledger row (option antwc_action_<row_id>, not autoloaded): what the leg is for, and
 * the Authorize.net transaction it claimed once one was verified.
 */
class Action_Store
{
    private const PREFIX = 'antwc_action_';

    /**
     * Idempotent: an existing record comes back unchanged, unless it is for different money.
     *
     * @param array<string, mixed> $row Ledger row (id, order_id, amount, currency).
     * @return array<string, mixed>
     */
    public function create(array $row, string $mode, int $now): array
    {
        $row_id = (string) ($row['id'] ?? '');
        if (!self::valid_id($row_id)) {
            throw new \RuntimeException('The payment row id is not usable.');
        }
        $existing = $this->get($row_id);
        if ($existing !== null) {
            if ($existing['amount'] !== (string) ($row['amount'] ?? '') || $existing['currency'] !== strtoupper((string) ($row['currency'] ?? '')) || (int) $existing['order_id'] !== (int) ($row['order_id'] ?? 0)) {
                throw new \RuntimeException('This payment row was started for a different order or amount.');
            }

            return $existing;
        }

        $record = array(
            'row_id' => $row_id,
            'order_id' => (int) ($row['order_id'] ?? 0),
            'amount' => (string) ($row['amount'] ?? ''),
            'currency' => strtoupper((string) ($row['currency'] ?? '')),
            'mode' => $mode,
            'created_at' => $now,
            'cancelled' => false,
            'claim' => null,
        );
        add_option(self::PREFIX . $row_id, wp_json_encode($record), '', 'no');

        return $this->get($row_id) ?? $record;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $row_id): ?array
    {
        if (!self::valid_id($row_id)) {
            return null;
        }
        $raw = get_option(self::PREFIX . $row_id, null);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($record) || !isset($record['row_id'], $record['amount'], $record['currency'], $record['mode'], $record['created_at'])) {
            return null;
        }
        $record['cancelled'] = !empty($record['cancelled']);
        $record['claim'] = isset($record['claim']) && is_array($record['claim']) ? $record['claim'] : null;

        return $record;
    }

    /**
     * @param array<string, mixed> $claim trans_id, amount, currency, card_type, last4, auth_code, submitted_at.
     */
    public function set_claim(string $row_id, array $claim): void
    {
        $this->patch($row_id, 'claim', $claim);
    }

    public function mark_cancelled(string $row_id): void
    {
        $this->patch($row_id, 'cancelled', true);
    }

    public static function valid_id(string $row_id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $row_id) === 1;
    }

    /**
     * @param mixed $value
     */
    private function patch(string $row_id, string $key, $value): void
    {
        $record = $this->get($row_id);
        if ($record === null) {
            throw new \RuntimeException('No action record for this payment row.');
        }
        $record[$key] = $value;
        update_option(self::PREFIX . $row_id, wp_json_encode($record), 'no');
    }
}
