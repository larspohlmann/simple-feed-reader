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
 * Tokens live 7 days with no refresh flow; the only revocation channel is the
 * per-request user reload the Doctrine provider performs. That reload catches
 * a STATUS change (App\Security\UserChecker) but nothing about the password,
 * since no part of the token derives from the hash — so password reset, the
 * one action a compromised user takes to evict an attacker, evicted nobody;
 * a stolen bearer token kept full access for its whole week.
 *
 * Comparing `iat` against User::getPasswordChangedAt() closes that without
 * server-side token storage, which the deployment target (Strato shared
 * hosting: no Redis, no daemon) couldn't carry anyway.
 *
 * STRICTLY BEFORE — `<`, never `<=`. A reset-then-immediate-login often stamps
 * `iat` in the SAME second as passwordChangedAt; `<=` would reject that
 * brand-new token and make reset look broken to the person who just used it.
 * `<` costs a same-second token surviving, which needs an attacker mid-login
 * in that window, already holding the old password.
 *
 * FAILS CLOSED on a missing `iat`: if a password change is recorded and the
 * token can't prove it postdates it, the token is refused. Lexik always
 * stamps `iat`, so this is unreachable today — kept so a future encoder
 * change degrades into rejection, not a silently disabled check.
 *
 * JWT_AUTHENTICATED, not JWT_DECODED: the decoded event carries only the
 * payload, forcing a duplicate user lookup; this one fires later with the
 * user already loaded. Either way the client sees the same opaque 401
 * `unauthorized` problem+json as any other JWT failure (via
 * JWTAuthenticator::onAuthenticationFailure -> JWT_INVALID ->
 * App\EventListener\JwtFailureResponseListener) — the holder is never told
 * the password changed, since whoever holds a dead token may be the thief.
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
