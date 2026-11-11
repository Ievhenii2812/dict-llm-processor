<?php

namespace App\Services\Import;

/**
 * Thrown by a provider when one specific model cannot serve a request,
 * for a reason other than an exhausted quota (that is AIRateLimitException):
 *
 *  - permanent = false: the model is overloaded (HTTP 503) or hit a short
 *    per-minute limit; it is parked for $retryAfterSeconds and tried again.
 *  - permanent = true:  the model does not exist for this key (HTTP 404/403)
 *    or was decommissioned; it is skipped for the rest of the process.
 *
 * Only ChainsModels catches this; it never reaches the commands.
 */
class AIModelUnavailableException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $permanent = false,
        public readonly int $retryAfterSeconds = 30,
    ) {
        parent::__construct($message);
    }
}
