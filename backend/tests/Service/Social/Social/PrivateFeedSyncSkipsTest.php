<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\SocialAccount;
use App\Enum\PrivateFeedSyncSkip;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\PrivateFeedSyncSkips;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class PrivateFeedSyncSkipsTest extends TestCase
{
    use AssignsEntityIds;

    private const int ACCOUNT_ID = 31;

    public function testAFailureThatClosedTheEntityManagerRecordsTheSkipThroughAFreshOne(): void
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $closed->expects($this->never())->method('clear');
        $closed->expects($this->never())->method('flush');
        $fresh = $this->createMock(ObjectManager::class);
        $reloaded = self::withId(SocialAccounts::bluesky(), self::ACCOUNT_ID);
        $fresh->method('find')->with(SocialAccount::class, self::ACCOUNT_ID)->willReturn($reloaded);
        $fresh->expects($this->once())->method('flush');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->once())->method('resetManager')->willReturn($fresh);
        $skips = new PrivateFeedSyncSkips(
            new NaiveUtcClock(new MockClock('2026-10-17 12:00:00', 'UTC')),
            $closed,
            $registry,
            new NullLogger(),
        );

        $outcome = $skips->recordFailure(
            self::withId(SocialAccounts::bluesky(), self::ACCOUNT_ID),
            new \RuntimeException('The flush failed.'),
        );

        self::assertSame(PrivateFeedSyncSkip::Failed, $outcome->skip);
        self::assertSame(PrivateFeedSyncSkip::Failed, $reloaded->getLastSyncSkip());
        self::assertSame('2026-10-17 12:00:00', $reloaded->getLastCheckedAt()?->format('Y-m-d H:i:s'));
    }

    public function testAFailedAccountDeletedMeanwhileIsNotRecorded(): void
    {
        $open = $this->createMock(EntityManagerInterface::class);
        $open->method('isOpen')->willReturn(true);
        $open->expects($this->once())->method('clear');
        $open->expects($this->once())->method('find')->willReturn(null);
        $open->expects($this->never())->method('flush');
        $skips = new PrivateFeedSyncSkips(
            new NaiveUtcClock(new MockClock('2026-10-17 12:00:00', 'UTC')),
            $open,
            $this->createStub(ManagerRegistry::class),
            new NullLogger(),
        );

        $outcome = $skips->recordFailure(
            self::withId(SocialAccounts::bluesky(), self::ACCOUNT_ID),
            new \RuntimeException('The account went away.'),
        );

        self::assertSame(PrivateFeedSyncSkip::Failed, $outcome->skip);
    }
}
