<?php

namespace WCPOS\WooCommercePOS\AuthorizeNetTerminal\Services;

use WCPOS\WooCommercePOS\AuthorizeNetTerminal\Logger;

/**
 * An Authorize.net call that did not give a usable answer.
 *
 * Indeterminate: the request may have been processed (transport loss, non-2xx, unreadable body).
 * Determinate: Authorize.net answered resultCode "Error" with a code.
 */
class AuthorizeNetException extends \RuntimeException
{
    /** @var string */
    private $apiCode;
    /** @var bool */
    private $indeterminate;

    public function __construct(string $message, string $api_code = '', bool $indeterminate = false)
    {
        parent::__construct(Logger::redact_untrusted_text($message));
        $this->apiCode = $api_code;
        $this->indeterminate = $indeterminate;
    }

    public function getApiCode(): string
    {
        return $this->apiCode;
    }

    public function isIndeterminate(): bool
    {
        return $this->indeterminate;
    }
}
