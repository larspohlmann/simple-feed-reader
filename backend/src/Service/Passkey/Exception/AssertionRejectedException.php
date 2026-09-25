<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/**
 * Any failed check on a login assertion. One type on purpose: naming the failed check would help an attacker
 * probing the endpoint more than a legitimate caller, who can only retry the ceremony.
 */
final class AssertionRejectedException extends \RuntimeException implements PasskeySignInFailure
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The passkey assertion failed verification.', previous: $previous);
    }
}
