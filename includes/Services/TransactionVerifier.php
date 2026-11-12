<?php

namespace WCPOS\WooCommercePOS\AuthorizeNetTerminal\Services;

use WCPOS\WooCommercePOSPro\Payments\Server\Money_Units;

/**
 * Decides whether an Authorize.net transaction is money taken for this leg. Every doubt refuses.
 */
class TransactionVerifier
{
    /** A submit time this far past "now" (seconds) is clock skew; beyond it the record is refused. */
    public const FUTURE_SKEW_SECONDS = 300;

    private const SALE_TYPES = array('authCaptureTransaction', 'priorAuthCaptureTransaction');
    private const CAPTURED_STATUSES = array('capturedPendingSettlement', 'settledSuccessfully');

    /**
     * @param array<string, mixed> $details getTransactionDetails "transaction".
     * @param string[] $merchant_currencies
     * @return array<string, mixed>
     */
    public function verify(array $details, string $amount, string $currency, array $merchant_currencies, int $not_before, int $now): array
    {
        if (count($details) === 0) {
            return self::refuse('not_found', 'Authorize.net has no record of that payment.');
        }
        if (!in_array($details['transactionType'] ?? '', self::SALE_TYPES, true)) {
            return self::refuse('wrong_type', 'That Authorize.net transaction is not a completed sale.');
        }
        if (!in_array($details['transactionStatus'] ?? '', self::CAPTURED_STATUSES, true) || self::scalar($details, 'responseCode') !== '1') {
            return self::refuse('not_captured', 'That Authorize.net payment was not approved and captured.');
        }
        $currency = strtoupper($currency);
        $known = array_map('strtoupper', array_filter($merchant_currencies, 'is_string'));
        if (count($known) === 0 || !in_array($currency, $known, true)) {
            return self::refuse('currency_mismatch', 'The Authorize.net account does not take payments in ' . $currency . '.');
        }
        $settled = self::settle_minor($details['settleAmount'] ?? null, $currency);
        if ($settled === null || $settled <= 0 || $settled !== Money_Units::minor($amount, $currency)) {
            return self::refuse('amount_mismatch', 'That Authorize.net payment is not for ' . $currency . ' ' . $amount . '.');
        }
        $submitted = self::parse_utc(self::scalar($details, 'submitTimeUTC'));
        if ($submitted === null || $submitted < $not_before) {
            return self::refuse('too_old', 'That Authorize.net payment was taken before this sale started.');
        }
        if ($submitted > $now + self::FUTURE_SKEW_SECONDS) {
            return self::refuse('in_future', 'That Authorize.net payment has a time in the future; check the server clock.');
        }

        $card = isset($details['payment']['creditCard']) && is_array($details['payment']['creditCard']) ? $details['payment']['creditCard'] : array();

        return array(
            'ok' => true,
            'trans_id' => self::scalar($details, 'transId'),
            'amount' => $amount,
            'currency' => $currency,
            'card_type' => self::scalar($card, 'cardType'),
            'last4' => self::last4(self::scalar($card, 'cardNumber')),
            'auth_code' => self::scalar($details, 'authCode'),
            'submitted_at' => $submitted,
        );
    }

    /**
     * Minor units of an Authorize.net amount (a JSON number), or null when it is not a number.
     *
     * @param mixed $value
     */
    public static function settle_minor($value, string $currency): ?int
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }
        $decimals = self::decimals($currency);

        return Money_Units::minor(number_format((float) $value, $decimals, '.', ''), $currency);
    }

    /** The currency's minor-unit exponent, as Money_Units knows it. */
    public static function decimals(string $currency): int
    {
        $one = Money_Units::major(1, $currency);
        $dot = strpos($one, '.');

        return $dot === false ? 0 : strlen($one) - $dot - 1;
    }

    /**
     * Strict ISO-8601 UTC ("2026-11-11T10:00:00Z", optional fraction; "Z", "+00:00" or none, the field
     * being UTC by name). Anything else, including other offsets, is null.
     */
    public static function parse_utc(string $value): ?int
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,7})?(?:Z|\+00:00)?$/', $value, $m) !== 1) {
            return null;
        }
        list(, $y, $mo, $d, $h, $mi, $s) = array_map('intval', $m);
        if (!checkdate($mo, $d, $y) || $h > 23 || $mi > 59 || $s > 59) {
            return null;
        }

        return gmmktime($h, $mi, $s, $mo, $d, $y);
    }

    public static function last4(string $masked): string
    {
        $digits = preg_replace('/\D/', '', $masked);

        return is_string($digits) && strlen($digits) >= 4 ? substr($digits, -4) : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function scalar(array $data, string $key): string
    {
        return isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function refuse(string $reason, string $message): array
    {
        return array('ok' => false, 'reason' => $reason, 'message' => $message);
    }
}
