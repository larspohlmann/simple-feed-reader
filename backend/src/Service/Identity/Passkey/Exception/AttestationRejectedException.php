<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/** Any failed check on a registration attestation, collapsed into one type for the same reason as an assertion. */
final class AttestationRejectedException extends \RuntimeException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct('The passkey attestation failed verification.', previous: $previous);
    }
}
