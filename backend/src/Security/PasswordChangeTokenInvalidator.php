<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Binds a stateless JWT to the password it was issued under.
 *
 * Tokens live 7 days with no refresh flow, so the only revocation channel is the
 * per-request user reload the Doctrine provider performs. That reload catches a
 * STATUS change (App\Security\UserChecker reads status, so a suspension takes
 * effect on the next request) but nothing about the password, since no part of
 * the token derives from the hash. The consequence: password reset, the one
 * action a compromised user takes to evict an attacker, evicted nobody — the
 * attacker's stolen bearer token kept full API access for its whole week.
 *
 * Comparing `iat` against User::getPasswordChangedAt() closes that without
 * server-side token storage, which the deployment target (Strato shared hosting:
 * no Redis, no daemon) could not carry anyway.
 *
 * STRICTLY BEFORE. `<`, never `<=`. A user who resets and immediately signs back
 * in gets a token whose whole-second `iat` routinely lands in the SAME second as
 * passwordChangedAt; under `<=` that brand-new token would be rejected and reset
 * would look broken to the person who just used it. The cost of `<` is that a
 * token minted in the same second survives — an attacker would have to be
 * mid-login within that one-second window, having already lost the password.
 *
 * FAILS CLOSED ON A MISSING `iat`: if the account has a recorded password change
 * and the token cannot prove it postdates it, the token is refused. Lexik always
 * stamps `iat`, so this is unreachable today — it is here so a future encoder
 * change degrades into rejection, not a silently disabled revocation check.
 *
 * WHY JWT_AUTHENTICATED, not JWT_DECODED: on_jwt_decoded carries only the
 * payload, so the user would be looked up again — a second SELECT on every
 * authenticated request, duplicating the provider's identity resolution. This
 * event fires a few lines later with the user already loaded. Rejection reaches
 * the client identically either way: AuthenticatorManager routes it through
 * JWTAuthenticator::onAuthenticationFailure -> JWT_INVALID ->
 * App\EventListener\JwtFailureResponseListener, the same opaque 401 `unauthorized`
 * problem+json as every other JWT failure. The holder is never told the password
 * changed — whoever presents a dead token may be the thief, and needs no hint.
 */
#[AsEventListener(event: Events::JWT_AUTHENTICATED, method: 'onJwtAuthenticated')]
final class PasswordChangeTokenInvalidator
{
    public function onJwtAuthenticated(JWTAuthenticatedEvent $event): void
    {
        $user = $event->getToken()->getUser();

        if (!$user instanceof User) {
            return;
        }

        $changedAt = $user->getPasswordChangedAt();

        if (null === $changedAt) {
            // Never recorded a change: nothing to revoke. Rows predating the
            // column land here, which is why the migration can be additive.
            return;
        }

        $issuedAt = $event->getPayload()['iat'] ?? null;

        if (!\is_int($issuedAt)) {
            throw new InvalidTokenException('JWT carries no usable "iat" claim.');
        }

        if ($issuedAt < $changedAt->getTimestamp()) {
            throw new InvalidTokenException('JWT predates the account\'s last password change.');
        }
    }
}
