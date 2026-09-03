<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

use App\Exception\ApiException;

/**
 * A WebAuthn attestation ("registration") failed verification: wrong
 * challenge, wrong origin, wrong relying-party id, or a corrupt/unparseable
 * credential. AttestationVerifier catches all of these at the WebAuthn/CBOR
 * library boundary and rewrites them to this single type.
 *
 * Collapsed into one case on purpose, like UnknownChallengeException: the
 * client already knows what it sent, so naming the exact failed check would
 * help an attacker probing the endpoint more than a legitimate caller, who
 * can only retry the ceremony regardless of which check failed.
 */
final class AttestationRejectedException extends ApiException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct(
            'passkey_attestation_rejected',
            400,
            'Passkey registration rejected',
            'The passkey could not be verified.',
            previous: $previous,
        );
    }
}
