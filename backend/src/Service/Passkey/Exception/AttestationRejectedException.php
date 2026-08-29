<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

use App\Exception\ApiException;

/**
 * A WebAuthn attestation ("registration") response failed verification:
 * wrong challenge, wrong origin, wrong relying-party id, a corrupt or
 * unparseable credential — AttestationVerifier catches every one of these at
 * the WebAuthn/CBOR library boundary and rewrites it to this single type.
 *
 * Collapsed into one case on purpose, the same reasoning
 * UnknownChallengeException gives: the client already knows what it sent, so
 * naming the exact failed check tells an attacker probing the endpoint more
 * than it tells a legitimate caller, who can only retry the ceremony from
 * scratch regardless of which check failed.
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
