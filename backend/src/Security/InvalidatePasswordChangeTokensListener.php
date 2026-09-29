<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Refuses a JWT issued strictly before the password last changed (`<`, not `<=`: a login in the reset's second must
 * survive) and fails closed without `iat`. The 401 never says why. Threat model: docs/security.md#password-change
 */
#[AsEventListener(event: Events::JWT_AUTHENTICATED, method: 'onJwtAuthenticated')]
final readonly class InvalidatePasswordChangeTokensListener
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
