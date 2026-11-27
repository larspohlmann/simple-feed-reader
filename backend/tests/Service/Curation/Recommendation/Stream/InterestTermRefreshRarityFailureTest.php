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
use App\Service\Curation\Recommendation\Stream\InterestTermRefresh;
use App\Service\Curation\Recommendation\Stream\Model\DueInterestTermsModel;
use App\Service\Foundation\Clock\NaiveUtcClock;
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
        $refresh = self::getContainer()->get(InterestTermRefresh::class);
        self::assertInstanceOf(InterestTermRefresh::class, $refresh);
        $due = new DueInterestTermsModel(
            $this->owner,
            $this->requireConnection($this->owner),
            'Likes solar power.',
            $this->profileGeneratedAt(),
        );

        try {
            $refresh->refresh($due);
            self::fail('The rarity failure should have propagated.');
        } catch (\RuntimeException $exception) {
            self::assertSame('rarity unavailable', $exception->getMessage());
        }

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
