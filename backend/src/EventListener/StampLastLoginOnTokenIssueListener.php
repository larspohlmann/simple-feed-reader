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
 * Stamps the last sign-in when a JWT is created, the one point both sign-in paths share; a refresh token, if one is
 * ever added, would count too. Listens on Events::JWT_CREATED, not the class: Lexik dispatches by that name.
 */
#[AsEventListener(event: Events::JWT_CREATED, method: '__invoke')]
final readonly class StampLastLoginOnTokenIssueListener
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
