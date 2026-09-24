<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Registered on Events::JWT_CREATED, not the class name — see StampLastLoginOnTokenIssue. */
#[AsEventListener(event: Events::JWT_CREATED, method: '__invoke')]
final readonly class AddUserIdClaimOnTokenIssue
{
    public const string CLAIM = 'userId';

    public function __invoke(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $event->setData([
            ...$event->getData(),
            self::CLAIM => $user->getId() ?? throw new \LogicException('A signed-in user must have an id.'),
        ]);
    }
}
