<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

use App\Exception\ApiException;

/**
 * A WebAuthn assertion ("login") failed verification: unenrolled credential
 * id, wrong challenge, wrong origin, wrong relying-party id, a stalled
 * signature counter, or a corrupt/unparseable response. AssertionVerifier
 * catches all of these at the WebAuthn/CBOR boundary — or resolves an
 * unknown credential id itself — and rewrites them to this one type.
 *
 * Collapsed into one case on purpose, like UnknownChallengeException and
 * AttestationRejectedException: naming the exact failed check would help an
 * attacker probing the endpoint more than a legitimate caller, who can only
 * retry the ceremony regardless of which check failed.
 *
 * PasskeyAuthenticator always catches this and rethrows it as a plain
 * Symfony AuthenticationException before it reaches this class's own
 * exception listener (see that class's docblock), so $status and $type here
 * never reach the client — every passkey login failure surfaces through
 * App\Security\LoginFailureHandler, the same path password login uses.
 */
final class AssertionRejectedException extends ApiException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'passkey_assertion_rejected',
            401,
            'Passkey login rejected',
            'The passkey could not be verified.',
            previous: $previous,
        );
    }
}
