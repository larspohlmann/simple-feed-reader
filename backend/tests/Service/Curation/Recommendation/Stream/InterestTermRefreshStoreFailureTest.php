<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Stream;

use App\Entity\InterestTermRun;
use App\Entity\InterestTermSet;
use App\Entity\User;
use App\Enum\RunStatus;
use App\Repository\EntryMembershipSweepRepository;
use App\Repository\InterestTermRepository;
use App\Repository\InterestTermSetRepository;
use App\Service\Curation\Recommendation\Stream\DueInterestTermFinder;
use App\Service\Curation\Recommendation\Stream\InterestTermRarity;
use App\Service\Curation\Recommendation\Stream\InterestTermRefresh;
use App\Service\Curation\Recommendation\Stream\InterestTermStore;
use App\Service\Curation\Recommendation\Stream\InterestTermSweep;
use App\Service\Curation\Recommendation\Stream\Model\DueInterestTermsModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermRefreshResult;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\Membership\SettledCeiling;
use App\Tests\DbTestCase;
use App\Tests\Support\RequiresServices;
use App\Tests\Support\SeedsProfiledAccounts;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubChatClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;

final class InterestTermRefreshStoreFailureTest extends DbTestCase
{
    use RequiresServices;
    use SeedsProfiledAccounts;
    use SeedsUsers;

    private const string REPLY = '{"terms":[{"language":"en","term":"solar","weight":8}]}';

    private \stdClass $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new \stdClass();
        $this->store->failing = true;
        $store = $this->store;
        $clock = new class ($store) implements ClockInterface {
            public function __construct(private readonly \stdClass $store)
            {
            }

            public function now(): \DateTimeImmutable
            {
                if ($this->store->failing) {
                    throw new \RuntimeException('store unavailable');
                }

                return new \DateTimeImmutable('2026-11-27 10:00:00');
            }
        };
        self::getContainer()->set(InterestTermStore::class, new InterestTermStore(
            $this->entityManager,
            $this->requireFromContainer(InterestTermSetRepository::class),
            $this->requireFromContainer(InterestTermRepository::class),
            new SettledCeiling($this->requireFromContainer(EntryMembershipSweepRepository::class), $clock),
            $clock,
        ));
    }

    public function testAStoreThatKeepsFailingCostsThreePaidCallsThenFailsTheRunAndStopsTheAccountBeingDue(): void
    {
        $owner = $this->profiledAccount('terms-store-failure@example.test');
        $chat = $this->requireFromContainer(StubChatClient::class);
        foreach (range(1, 3) as $ignored) {
            $chat->queueContent(self::REPLY);
        }

        foreach (range(1, 3) as $ignored) {
            try {
                $this->requireFromContainer(InterestTermRefresh::class)->refresh($this->dueNow());
                self::fail('The store failure should propagate.');
            } catch (\RuntimeException $exception) {
                self::assertSame('store unavailable', $exception->getMessage());
            }
            $this->freshProcess();
        }
        $fourth = $this->requireFromContainer(InterestTermRefresh::class)->refresh($this->dueNow());

        self::assertSame(InterestTermRefreshResult::Failed, $fourth);
        self::assertCount(3, $chat->calls());
        $manager = $this->manager();
        $run = $manager->getRepository(InterestTermRun::class)->findOneBy(['user' => $owner->requireId()]);
        self::assertInstanceOf(InterestTermRun::class, $run);
        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertNull($manager->find(InterestTermSet::class, $owner->requireId()));
        self::assertSame([], $this->requireFromContainer(DueInterestTermFinder::class)->findDue());
    }

    public function testAFailingAccountStopsTheSweepAndTheNextAccountRefreshesOnTheNextPass(): void
    {
        $this->profiledAccount('terms-store-first@example.test');
        $second = $this->profiledAccount('terms-store-second@example.test');
        $chat = $this->requireFromContainer(StubChatClient::class);
        foreach (range(1, 3) as $ignored) {
            $chat->queueContent(self::REPLY);
        }
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };

        $stopped = $this->sweep($logger)->refreshDue();

        self::assertSame(2, $stopped->due);
        self::assertSame(0, $stopped->stored);
        self::assertCount(1, $chat->calls());
        self::assertCount(1, $logger->records);
        self::assertInstanceOf(\RuntimeException::class, $logger->records[0][1]['exception']);
        self::assertSame('store unavailable', $logger->records[0][1]['exception']->getMessage());
        $this->freshProcess();
        self::assertNull(
            $this->manager()->getRepository(InterestTermRun::class)->findOneBy(['user' => $second->requireId()]),
        );

        $this->store->failing = false;
        $next = $this->sweep($logger)->refreshDue();

        self::assertSame(2, $next->due);
        self::assertSame(2, $next->stored);
        self::assertCount(3, $chat->calls());
        self::assertInstanceOf(
            InterestTermSet::class,
            $this->manager()->find(InterestTermSet::class, $second->requireId()),
        );
    }

    private function sweep(AbstractLogger $logger): InterestTermSweep
    {
        $manager = $this->manager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return new InterestTermSweep(
            $this->requireFromContainer(DueInterestTermFinder::class),
            $this->requireFromContainer(InterestTermRefresh::class),
            $this->requireFromContainer(InterestTermSetRepository::class),
            $this->requireFromContainer(InterestTermRarity::class),
            new NaiveUtcClock(new MockClock('2026-11-27 10:00:00')),
            $logger,
            $manager,
        );
    }

    private function dueNow(): DueInterestTermsModel
    {
        $due = $this->requireFromContainer(DueInterestTermFinder::class)->findDue();
        self::assertCount(1, $due);

        return $due[0];
    }

    private function freshProcess(): void
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $registry->resetManager();
    }

    private function manager(): ObjectManager
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry->getManager();
    }
}
