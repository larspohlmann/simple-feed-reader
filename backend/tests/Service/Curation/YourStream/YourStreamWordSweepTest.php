<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\YourStream;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Entity\YourStreamState;
use App\Entity\YourStreamTuning;
use App\Entity\YourStreamWord;
use App\Enum\FeedLanguage;
use App\Enum\YourStreamPrecision;
use App\Repository\EntryMembershipSweepRepository;
use App\Repository\ReadingHistoryRepository;
use App\Repository\SavedSearchRepository;
use App\Repository\YourStreamSourceRepository;
use App\Repository\YourStreamStateRepository;
use App\Repository\YourStreamWordRepository;
use App\Service\Curation\YourStream\Factory\EntryStemmingFactory;
use App\Service\Curation\YourStream\InterestArticles;
use App\Service\Curation\YourStream\Model\InterestInputsModel;
use App\Service\Curation\YourStream\Model\StreamWordModel;
use App\Service\Curation\YourStream\Model\YourStreamWordSweepReportModel;
use App\Service\Curation\YourStream\Pass\RaritySweepPass;
use App\Service\Curation\YourStream\SearchTermStems;
use App\Service\Curation\YourStream\YourStreamBackground;
use App\Service\Curation\YourStream\YourStreamWordBuilder\YourStreamWordBuilder;
use App\Service\Curation\YourStream\YourStreamWordBuilder\YourStreamWordBuilderInterface;
use App\Service\Curation\YourStream\YourStreamWordSelection;
use App\Service\Curation\YourStream\YourStreamWordStore;
use App\Service\Curation\YourStream\YourStreamWordSweep;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use App\Service\Library\Search\Membership\SettledCeiling;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RequiresServices;
use App\Tests\Support\StateMarks;
use App\Tests\Support\StemmingWiring;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\MockClock;

final class YourStreamWordSweepTest extends DbTestCase
{
    use RequiresServices;

    private MockClock $clock;
    private RecordingLogger $logger;
    private int $nextEntry = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-11-28 10:00:00');
        $this->logger = new RecordingLogger();
    }

    public function testAnAccountWithAFavouriteGetsItsFirstListAndAStateRowAtTheCeiling(): void
    {
        $user = $this->readerOfBundesliga('first-list@example.com');
        $ceiling = $this->lastEntryId();

        $report = $this->sweep();

        self::assertSame(['due' => 1, 'checked' => 0, 'built' => 1, 'failed' => 0], $report->toLogContext());
        self::assertSame(
            [StemmingWiring::stemmer()->stem('bundesliga', FeedLanguage::German)],
            $this->readStems($user),
        );
        self::assertSame($ceiling, $this->requireState($user)->matchedUpToEntryId());
    }

    public function testAnAccountCheckedWithinTheHourIsNotCheckedAgain(): void
    {
        $this->readerOfBundesliga('within-hour@example.com');
        $this->sweep();
        $this->clock->modify('+59 minutes');

        self::assertSame(['due' => 0, 'checked' => 0, 'built' => 0, 'failed' => 0], $this->sweep()->toLogContext());
    }

    public function testAnUnchangedAccountIsOnlyCheckedAfterAnHour(): void
    {
        $this->readerOfBundesliga('unchanged@example.com');
        $this->sweep();
        $this->clock->modify('+61 minutes');

        self::assertSame(['due' => 1, 'checked' => 1, 'built' => 0, 'failed' => 0], $this->sweep()->toLogContext());
    }

    public function testANewFavouriteRebuildsTheListWithinTheHour(): void
    {
        $user = $this->readerOfBundesliga('new-favourite@example.com');
        $this->sweep();
        $this->favourite($this->requireUser($user), $this->requireFeed($user), 'Bundesliga gestern');
        $this->clock->modify('+61 minutes');

        self::assertSame(['due' => 1, 'checked' => 0, 'built' => 1, 'failed' => 0], $this->sweep()->toLogContext());
    }

    public function testAnUnchangedAccountIsRebuiltAfterADay(): void
    {
        $this->readerOfBundesliga('daily@example.com');
        $this->sweep();
        $this->clock->modify('+24 hours 1 minute');

        self::assertSame(['due' => 1, 'checked' => 0, 'built' => 1, 'failed' => 0], $this->sweep()->toLogContext());
    }

    public function testAnEmptyBuildKeepsTheStateRowAndItsMark(): void
    {
        $user = $this->readerOfBundesliga('emptied@example.com');
        $this->sweep();
        $mark = $this->requireState($user)->matchedUpToEntryId();
        $states = $this->entityManager->getRepository(EntryState::class)->findBy(['user' => $user->requireId()]);
        foreach ($states as $state) {
            StateMarks::clearFavorite($state);
        }
        $this->entityManager->flush();
        $this->clock->modify('+61 minutes');

        $report = $this->sweep();

        self::assertSame(1, $report->built);
        self::assertSame([], $this->readStems($user));
        self::assertSame($mark, $this->requireState($user)->matchedUpToEntryId());
    }

    public function testADisabledAccountIsNeitherCheckedNorBuilt(): void
    {
        $user = $this->readerOfBundesliga('disabled@example.com');
        RecommendationRunFixtures::fromContainer(self::getContainer())
            ->storeYourStreamTuning($user, new YourStreamTuning(false, YourStreamPrecision::Wide));

        $report = $this->sweep();

        self::assertSame(0, $report->due);
        self::assertNull($this->states()->findForUser($this->requireUser($user)));
    }

    public function testOneFailingAccountDoesNotStopTheOthers(): void
    {
        $failing = $this->account('failing@example.com');
        $healthy = $this->account('healthy@example.com');
        $builder = $this->builderFailingFor($failing->requireId());

        $report = $this->sweep(builder: $builder);

        self::assertSame(['due' => 2, 'checked' => 0, 'built' => 1, 'failed' => 1], $report->toLogContext());
        self::assertSame(['solar'], $this->readStems($healthy));
        self::assertSame([], $this->readStems($failing));
        self::assertCount(1, $this->logger->records);
        self::assertSame('error', $this->logger->records[0]['level']);
        self::assertSame($failing->requireId(), $this->logger->records[0]['context']['userId'] ?? null);
        $this->clock->modify('+30 minutes');
        self::assertSame(0, $this->sweep(builder: $builder)->due);
    }

    public function testAClosedEntityManagerEndsTheRun(): void
    {
        $failing = $this->account('closing@example.com');
        $this->account('after-closing@example.com');
        $builder = $this->builderFailingFor($failing->requireId(), $this->entityManager);

        $report = $this->sweep(builder: $builder);

        self::assertSame(['due' => 2, 'checked' => 0, 'built' => 0, 'failed' => 1], $report->toLogContext());
    }

    public function testNoAccountStartsAtTheDeadline(): void
    {
        $user = $this->readerOfBundesliga('deadline@example.com');

        $report = $this->sweep(SweepBudgetModel::seconds(0));

        self::assertSame(['due' => 1, 'checked' => 0, 'built' => 0, 'failed' => 0], $report->toLogContext());
        self::assertNull($this->states()->findForUser($this->requireUser($user)));
    }

    private function sweep(
        ?SweepBudgetModel $budget = null,
        ?YourStreamWordBuilderInterface $builder = null,
    ): YourStreamWordSweepReportModel {
        return $this->sweeper($builder)->sweep($budget ?? SweepBudgetModel::seconds(60));
    }

    private function sweeper(?YourStreamWordBuilderInterface $builder): YourStreamWordSweep
    {
        $clock = new NaiveUtcClock($this->clock);
        $sources = new YourStreamSourceRepository($this->entityManager);
        $background = new YourStreamBackground(
            $sources,
            new EntryStemmingFactory(StemmingWiring::equivalents(), StemmingWiring::stopWordStems()),
            $clock,
        );

        return new YourStreamWordSweep(
            $this->states(),
            new InterestArticles(
                new ReadingHistoryRepository($this->entityManager),
                $this->requireFromContainer(SavedSearchRepository::class),
            ),
            $builder ?? new YourStreamWordBuilder(
                $sources,
                new SearchTermStems(StemmingWiring::equivalents(), StemmingWiring::stopWordStems()),
                $background,
                new YourStreamWordSelection(),
            ),
            $background,
            new YourStreamWordStore(
                $this->entityManager,
                $this->states(),
                $this->words(),
                new SettledCeiling($this->requireFromContainer(EntryMembershipSweepRepository::class), $this->clock),
                $clock,
            ),
            $clock,
            $this->logger,
            $this->entityManager,
        );
    }

    private function builderFailingFor(
        int $failingUserId,
        ?EntityManagerInterface $closing = null,
    ): YourStreamWordBuilderInterface {
        return new readonly class ($failingUserId, $closing) implements YourStreamWordBuilderInterface {
            public function __construct(private int $failingUserId, private ?EntityManagerInterface $closing)
            {
            }

            public function buildWords(InterestInputsModel $inputs, RaritySweepPass $pass): array
            {
                if ($inputs->userId === $this->failingUserId) {
                    $this->closing?->close();

                    throw new \RuntimeException('simulated build failure');
                }

                return [new StreamWordModel(FeedLanguage::English, 'solar', 'solar', 1.0, 3.0)];
            }
        };
    }

    private function readerOfBundesliga(string $email): User
    {
        $user = $this->account($email);
        $feed = new Feed('https://de.example.com/' . md5($email) . '.xml');
        $feed->recordTraits($feed->getTraits()->withLanguage(FeedLanguage::German));
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01')));
        $this->favourite($user, $feed, 'Bundesliga heute');
        $this->favourite($user, $feed, 'Bundesliga morgen');
        for ($index = 0; $index < 600; $index++) {
            $this->entry($feed, 'Wetterbericht', '2026-11-20 10:00:00');
        }
        $this->entityManager->flush();

        return $user;
    }

    private function account(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $user->approve(new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function favourite(User $user, Feed $feed, string $title): void
    {
        $state = new EntryState($user, $this->entry($feed, $title, '2026-09-01 10:00:00'));
        StateMarks::markFavorite($state);
        $this->entityManager->persist($state);
        $this->entityManager->flush();
    }

    private function entry(Feed $feed, string $title, string $effectiveAt): Entry
    {
        $at = new \DateTimeImmutable($effectiveAt);
        $entry = new Entry($feed, 'word-sweep-' . $this->nextEntry++, null, $title, $at, $at);
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function lastEntryId(): int
    {
        $id = $this->entityManager->getConnection()->fetchOne('SELECT MAX(id) FROM entry');
        self::assertIsNumeric($id);

        return (int) $id;
    }

    /** @return list<string> */
    private function readStems(User $user): array
    {
        return array_map(
            static fn (YourStreamWord $word): string => $word->getStem(),
            $this->words()->findForUser($user->requireId()),
        );
    }

    private function requireState(User $user): YourStreamState
    {
        $state = $this->states()->findForUser($this->requireUser($user));
        self::assertNotNull($state);

        return $state;
    }

    private function requireUser(User $user): User
    {
        $found = $this->entityManager->find(User::class, $user->requireId());
        self::assertNotNull($found);

        return $found;
    }

    private function requireFeed(User $user): Feed
    {
        $subscription = $this->entityManager->getRepository(Subscription::class)
            ->findOneBy(['user' => $user->requireId()]);
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription->getFeed();
    }

    private function states(): YourStreamStateRepository
    {
        return $this->requireFromContainer(YourStreamStateRepository::class);
    }

    private function words(): YourStreamWordRepository
    {
        return $this->requireFromContainer(YourStreamWordRepository::class);
    }
}
