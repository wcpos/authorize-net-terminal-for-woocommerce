<?php

namespace WCPOS\WooCommercePOS\AuthorizeNetTerminal\Tests\Conformance;

use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Server\Authorize_Net_Server_Provider;

/**
 * The real adapter with each operation recorded on the fixture's transcript: the lessons count what Pro
 * asked the adapter to do. Each entry is written on entry and then completed with `sent=`, the
 * Authorize.net requests the operation made, and `mode=`, the environment those requests went to (the
 * action's own environment when it sent none). Registered in place of Authorize_Net_Server_Provider; the
 * scripted Authorize.net sits behind `pre_http_request`.
 */
final class Recording_Authorize_Net_Provider extends Authorize_Net_Server_Provider
{
    /** @var Authorize_Net_Conformance_Fixture|null */
    public static $fixture;

    /**
     * The suite needs two readers, one online. The phone app is this provider's only reader, so the
     * fixture adds an offline second one here; it can never be chosen.
     */
    public function list_readers()
    {
        $readers = parent::list_readers();
        if (is_array($readers)) {
            $readers[] = array('id' => 'second', 'label' => 'Second', 'status' => 'offline');
        }

        return $readers;
    }

    /**
     * Create sends nothing; the action is the record the adapter stored for the row. In
     * create_indeterminate the first answer is then lost on its way back to Pro (the request dies after
     * the record is durable), which is the only way this create's response can go missing.
     */
    public function create_reader_action(array $row, string $reader_id)
    {
        $result = parent::create_reader_action($row, $reader_id);
        $ref = self::$fixture->action_mode((string) $row['id']) === null ? '' : 'antwc:' . $row['id'];
        $entry = self::$fixture->record('create', $ref, 'amount=' . $row['amount'] . ' currency=' . $row['currency'] . ' reader=' . $reader_id);
        $this->sent($entry, $ref);
        if (self::$fixture->lose_create()) {
            throw new Lost_Create_Response('The create response was lost.');
        }

        return $result;
    }

    public function fetch(string $ref)
    {
        $entry = self::$fixture->record('fetch', $ref, '');
        self::$fixture->before_fetch();
        $result = parent::fetch($ref);
        $this->sent($entry, $ref);

        return $result;
    }

    public function answer(string $ref, string $prompt_id, string $button_id)
    {
        $button = preg_match('/^txn:(\d+)$/', $button_id, $m) === 1 ? 'txn:' . self::$fixture->alias($m[1], 'txn') : $button_id;
        $entry = self::$fixture->record('answer', $ref, 'prompt=' . $prompt_id . ' button=' . $button);
        $result = parent::answer($ref, $prompt_id, $button_id);
        $this->sent($entry, $ref);

        return $result;
    }

    public function cancel(string $ref)
    {
        $entry = self::$fixture->record('cancel', $ref, '');
        $result = parent::cancel($ref);
        $this->sent($entry, $ref);

        return $result;
    }

    /** Refunds run after overnight settlement, so a partial amount goes through refundTransaction. */
    public function refund(array $row, int $refund_id, string $amount)
    {
        $action = (string) ($row['provider_refs']['action'] ?? '');
        $reference = (string) ($row['provider_refs']['transaction_id'] ?? '');
        $entry = self::$fixture->record('refund', $action, 'amount=' . $amount . ' currency=' . $row['currency'] . ' transaction_id=' . self::$fixture->alias($reference, 'txn'));
        self::$fixture->transport->settle();
        $result = parent::refund($row, $refund_id, $amount);
        $this->sent($entry, $action);

        return $result;
    }

    private function sent(int $entry, string $ref): void
    {
        $sent = self::$fixture->transport->take_sent();
        $mode = count($sent) > 0 ? 'sandbox' : self::$fixture->action_mode(strpos($ref, 'antwc:') === 0 ? substr($ref, 6) : '');
        self::$fixture->complete($entry, 'mode=' . ($mode === 'production' ? 'live' : ($mode === 'sandbox' ? 'test' : 'none')) . ' sent=' . (count($sent) > 0 ? implode(',', $sent) : 'none'));
    }
}
