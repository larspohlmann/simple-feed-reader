<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Exception\UnpersistedEntityException;
use App\Entity\User;
use App\EventListener\AddUserIdClaimOnTokenIssueListener;
use App\Tests\DbTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

final class AddUserIdClaimOnTokenIssueListenerTest extends DbTestCase
{
    public function testAnIssuedTokenCarriesTheAccountIdAsAClaim(): void
    {
        $this->entityManager->persist(new User('first@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $second = new User('second@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($second);
        $this->entityManager->flush();

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $claims = $tokens->parse($tokens->create($second));

        self::assertSame($second->getId(), $claims[AddUserIdClaimOnTokenIssueListener::CLAIM] ?? null);
    }

    public function testATokenForAnUnsavedAccountIsRefused(): void
    {
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        $this->expectException(UnpersistedEntityException::class);
        $this->expectExceptionMessage(User::class);

        $tokens->create(new User('unsaved@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z')));
    }
}
