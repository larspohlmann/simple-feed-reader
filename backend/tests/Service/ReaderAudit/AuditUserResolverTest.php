<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\ReaderAuditRepository;
use App\Service\ReaderAudit\AuditUserResolver;
use App\Service\ReaderAudit\Exception\NoAuditUserException;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Connection;

final class AuditUserResolverTest extends DbTestCase
{
    private const string MOMENT = '2026-07-01T00:00:00Z';

    public function testADigitOnlyNameIsTheUsersId(): void
    {
        $user = $this->userWithSubscriptions('by-id@example.com', 0);

        self::assertSame($user->requireId(), $this->resolver()->resolve((string) $user->requireId()));
    }

    public function testAnyOtherNameIsTheUsersEmail(): void
    {
        $user = $this->userWithSubscriptions('by-email@example.com', 0);

        self::assertSame($user->requireId(), $this->resolver()->resolve('by-email@example.com'));
    }

    public function testANameNobodyHasIsRefused(): void
    {
        $this->expectException(NoAuditUserException::class);

        $this->resolver()->resolve('nobody@example.com');
    }

    public function testNoNameResolvesToTheAccountWithTheMostSubscriptions(): void
    {
        $this->userWithSubscriptions('narrow@example.com', 1);
        $widest = $this->userWithSubscriptions('widest@example.com', 2);

        self::assertSame($widest->requireId(), $this->resolver()->resolve(null));
    }

    public function testNoNameWithoutAnySubscriptionIsRefused(): void
    {
        $this->userWithSubscriptions('unsubscribed@example.com', 0);

        $this->expectException(NoAuditUserException::class);

        $this->resolver()->resolve(null);
    }

    private function userWithSubscriptions(string $email, int $subscriptions): User
    {
        $moment = new \DateTimeImmutable(self::MOMENT);
        $user = new User($email, $moment);
        $this->em->persist($user);
        for ($position = 0; $position < $subscriptions; ++$position) {
            $feed = new Feed(sprintf('https://feeds.example.com/%s/%d.xml', rawurlencode($email), $position));
            $this->em->persist($feed);
            $this->em->persist(new Subscription($user, $feed, $moment));
        }
        $this->em->flush();

        return $user;
    }

    private function resolver(): AuditUserResolver
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return new AuditUserResolver(new ReaderAuditRepository($connection));
    }
}
