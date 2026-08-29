<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

use App\Exception\ApiException;

/**
 * A WebAuthn assertion ("login") response failed verification: an unenrolled
 * credential id, wrong challenge, wrong origin, wrong relying-party id, a
 * signature counter that failed to advance, a corrupt or unparseable
 * response — AssertionVerifier catches every one of these at the
 * WebAuthn/CBOR library boundary, or resolves them itself for an unknown
 * credential id, and rewrites them to this single type.
 *
 * Collapsed into one case on purpose, the same reasoning
 * UnknownChallengeException and AttestationRejectedException give: naming
 * the exact failed check would tell an attacker probing the endpoint more
 * than it tells a legitimate caller, who can only retry the ceremony from
 * scratch regardless of which check failed.
 *
 * PasskeyAuthenticator always catches this and rethrows it as a plain
 * Symfony AuthenticationException before it can reach this class's own
 * exception listener — see that class's docblock for why — so this type's
 * own $status and $type are never what a client actually receives; every
 * passkey login failure surfaces through App\Security\LoginFailureHandler
 * instead, the same one password login uses.
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
