<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Stream;

use App\Entity\InterestTermRun;
use App\Entity\InterestTermSet;
use App\Entity\User;
use App\Enum\RunStatus;
use App\Repository\ForYouStreamSourceRepository;
use App\Repository\InterestTermRepository;
use App\Service\Curation\Recommendation\Stream\Factory\EntryStemmingFactory;
use App\Service\Curation\Recommendation\Stream\InterestTermRarity;
use App\Service\Curation\Recommendation\Stream\InterestTermSweep;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsProfiledAccounts;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StemmingWiring;
use App\Tests\Support\StubChatClient;
use Psr\Clock\ClockInterface;

final class InterestTermRefreshRarityFailureTest extends DbTestCase
{
    use SeedsProfiledAccounts;
    use SeedsUsers;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->profiledAccount('terms-rarity-failure@example.test');
        $storedTerms = self::getContainer()->get(InterestTermRepository::class);
        self::assertInstanceOf(InterestTermRepository::class, $storedTerms);
        self::getContainer()->set(InterestTermRarity::class, new InterestTermRarity(
            new ForYouStreamSourceRepository($this->entityManager),
            $storedTerms,
            new EntryStemmingFactory(StemmingWiring::equivalents(), StemmingWiring::stopWordStems()),
            $this->entityManager,
            new NaiveUtcClock(new class implements ClockInterface {
                public function now(): \DateTimeImmutable
                {
                    throw new \RuntimeException('rarity unavailable');
                }
            }),
        ));
    }

    public function testARarityFailureAfterTheTermsAreStoredLeavesTheRunCompleted(): void
    {
        $chat = self::getContainer()->get(StubChatClient::class);
        self::assertInstanceOf(StubChatClient::class, $chat);
        $chat->queueContent('{"terms":[{"language":"en","term":"solar","weight":8}]}');
        $sweep = self::getContainer()->get(InterestTermSweep::class);
        self::assertInstanceOf(InterestTermSweep::class, $sweep);

        $report = $sweep->sweep(SweepBudgetModel::seconds(InterestTermSweep::UNHURRIED_BUDGET_SECONDS));

        self::assertSame(1, $report->stored);
        self::assertSame(0, $report->raritiesRefreshed);
        $this->entityManager->clear();
        $run = $this->entityManager->getRepository(InterestTermRun::class)->findOneBy(['user' => $this->owner]);
        self::assertInstanceOf(InterestTermRun::class, $run);
        self::assertSame(RunStatus::Completed, $run->getStatus());
        self::assertInstanceOf(
            InterestTermSet::class,
            $this->entityManager->find(InterestTermSet::class, $this->owner->requireId()),
        );
    }
}
