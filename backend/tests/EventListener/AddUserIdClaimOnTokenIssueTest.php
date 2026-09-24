<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\User;
use App\Tests\DbTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

final class AddUserIdClaimOnTokenIssueTest extends DbTestCase
{
    public function testAnIssuedTokenCarriesTheAccountIdAsAClaim(): void
    {
        $this->em->persist(new User('first@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $second = new User('second@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($second);
        $this->em->flush();

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $claims = $tokens->parse($tokens->create($second));

        self::assertSame($second->getId(), $claims['userId'] ?? null);
    }
}
