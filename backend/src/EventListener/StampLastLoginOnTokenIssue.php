<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Records when an account last signed in.
 *
 * Token issuance is the one point BOTH sign-in paths share: the password path
 * never reaches AuthController::login() — the json_login listener intercepts it
 * and Lexik's success handler writes the response — while the OAuth path mints
 * its token directly in OAuthSignIn::redeemLoginCode(). Hooking JWTCreatedEvent
 * covers both without either knowing about this listener.
 *
 * Fires once per genuine sign-in because the app issues no refresh tokens; if
 * one is ever added, refreshes would start counting as logins and this
 * listener must learn to tell them apart.
 *
 * The flush has no try/catch: reaching this point already required reading the
 * account from the same connection, so a write failing here means the database
 * is gone and the login is rightly failing anyway.
 *
 * Registered on Events::JWT_CREATED, the bundle's string constant, and NOT on
 * JWTCreatedEvent::class: JWTManager::generateJwtStringAndDispatchEvents()
 * dispatches with that explicit name, and Symfony's dispatcher only falls back
 * to the event's class name when none is given — listening on the class name
 * would silently never fire.
 */
#[AsEventListener(event: Events::JWT_CREATED, method: '__invoke')]
final readonly class StampLastLoginOnTokenIssue
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        // The kernel pins UTC, so the clock already reads in the naive-UTC
        // wall clock Doctrine persists.
        $user->setLastLoginAt($this->clock->now());
        $this->entityManager->flush();
    }
}
