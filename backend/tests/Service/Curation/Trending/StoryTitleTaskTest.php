<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Entity\Entry;
use App\Entity\StoryAiRun;
use App\Entity\StorySettings;
use App\Entity\TrendingStory;
use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Service\Curation\Ai\Crypto\ApiKeyCipher;
use App\Service\Curation\Ai\Exception\ProviderUnreachableException;
use App\Service\Curation\Ai\Exception\RetryableProviderException;
use App\Service\Curation\StoryAi\StoryAiSweep;
use App\Service\Curation\Trending\StoryTitleTask;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\TrendingFixtures;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

final class StoryTitleTaskTest extends DbTestCase
{
    private const string NOW = '2031-05-14 12:00:00';

    private const string BEFORE_THE_WEEK = '2031-05-07 11:59:00';

    private MockClock $clock;
    private TrendingFixtures $fixtures;
    private RecommendationRunFixtures $runFixtures;
    private User $reader;
    private int $feedCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock(self::NOW, 'UTC');
        self::getContainer()->set(NaiveUtcClock::class, new NaiveUtcClock($this->clock));
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->runFixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->reader = (new TrendingFixtures($this->entityManager))->reader('story-title-task@example.test');
        $this->reader->setLastLoginAt(new \DateTimeImmutable('2031-05-13 12:00:00'));
        $this->reader->setLocale('de');
        $this->entityManager->flush();
        $this->fixtures = (new TrendingFixtures($this->entityManager))->ownedBy($this->reader);
    }

    public function testAVisibleStoryGetsATitleInTheUsersLanguage(): void
    {
        $this->runFixtures->seedReadyAiSettingsFor($this->reader, 'qwen3-14b');
        $story = $this->shownStory(3);
        $this->chat()->queueContent(
            '{"title":"Hafenarbeiter in Hamburg streiken","synopsis":"Die Hafenarbeiter legen die Arbeit nieder."}',
        );

        $this->sweep()->runDue();

        self::assertSame(
            [
                'llm_title' => 'Hafenarbeiter in Hamburg streiken',
                'llm_synopsis' => 'Die Hafenarbeiter legen die Arbeit nieder.',
                'llm_title_member_count' => 3,
                'llm_title_attempted_at' => self::NOW,
            ],
            $this->titleColumnsOf($story),
        );
        self::assertStringContainsString('in German', $this->chat()->calls()[0]['messages'][0]['content']);
    }

    public function testATitleWithoutAUsableSynopsisIsKept(): void
    {
        $this->runFixtures->seedReadyAiSettingsFor($this->reader, 'qwen3-14b');
        $story = $this->shownStory(3);
        $this->chat()->queueContent('{"title":"T","synopsis":""}');
        $this->chat()->queueContent('{"title":"T2","synopsis":""}');

        $this->sweep()->runDue();
        $this->clock->modify('+2 hours');
        $this->sweep()->runDue();

        self::assertSame('T', $this->titleColumnsOf($story)['llm_title']);
        self::assertNull($this->titleColumnsOf($story)['llm_synopsis']);
        self::assertCount(1, $this->chat()->calls());
    }

    public function testTheRequestCarriesAtMostTwelveHeadlinesAndTheSchema(): void
    {
        $this->runFixtures->seedReadyAiSettingsFor($this->reader, 'qwen3-14b');
        $this->shownStory(14, str_repeat('word ', 180));
        $this->chat()->queueContent('{"title":"Hafenarbeiter in Hamburg streiken","synopsis":"S."}');

        $this->sweep()->runDue();

        $calls = $this->chat()->calls();
        self::assertCount(1, $calls);
        self::assertSame('story_title', $calls[0]['responseSchemaName']);
        self::assertSame(
            ['title', 'synopsis'],
            $calls[0]['responseSchema']['required'],
        );
        self::assertStringContainsString(rtrim(str_repeat('word ', 180)), $this->userMessageOf(0));
        self::assertSame(12, preg_match_all('/^\d+\. /m', $this->userMessageOf(0)));
        self::assertStringContainsString("\n\n12. ", $this->userMessageOf(0));
    }

    public function testAnUnusableReplyIsNotRetriedWithinTheHour(): void
    {
        $this->runFixtures->seedReadyAiSettingsFor($this->reader, 'qwen3-14b');
        $story = $this->shownStory(3);
        $this->chat()->queueContent('{}');
        $this->chat()->queueContent('{}');

        $this->sweep()->runDue();
        $this->clock->modify('+59 minutes');
        $this->sweep()->runDue();

        self::assertNull($this->titleColumnsOf($story)['llm_title']);
        self::assertSame(self::NOW, $this->titleColumnsOf($story)['llm_title_attempted_at']);
        self::assertCount(1, $this->chat()->calls());

        $this->clock->modify('+2 minutes');
        $this->sweep()->runDue();

        self::assertCount(2, $this->chat()->calls());
    }

    public function testATitleIsRewrittenOnceTheStoryDoubledSixHoursAfterIt(): void
    {
        $this->runFixtures->seedReadyAiSettingsFor($this->reader, 'qwen3-14b');
        $story = $this->shownStory(2);
        $this->storeTitle($story, 'Alter Titel', 2, '2031-05-14 05:00:00');
        $this->fixtures->join($story, '2031-05-14 11:00:00', $this->outletEntry('Hafenstreik geht weiter'));

        $this->sweep()->runDue();

        self::assertCount(0, $this->chat()->calls());

        $this->fixtures->join($story, '2031-05-14 11:30:00', $this->outletEntry('Hafenstreik dauert an'));
        $this->chat()->queueContent('{"title":"Hafenstreik in Hamburg dauert an"}');

        $this->sweep()->runDue();

        self::assertCount(1, $this->chat()->calls());
        self::assertSame('Hafenstreik in Hamburg dauert an', $this->titleColumnsOf($story)['llm_title']);
        self::assertSame(4, $this->titleColumnsOf($story)['llm_title_member_count']);
    }

    public function testADoubledStoryWaitsSixHoursAfterItsLastTitle(): void
    {
        $this->runFixtures->seedReadyAiSettingsFor($this->reader, 'qwen3-14b');
        $story = $this->shownStory(4);
        $this->storeTitle($story, 'Alter Titel', 2, '2031-05-14 06:01:00');

        $this->sweep()->runDue();

        self::assertCount(0, $this->chat()->calls());
    }

    public function testARateLimitStopsTheUsersSweepWithoutStamping(): void
    {
        $this->runFixtures->seedReadyAiSettingsFor($this->reader, 'qwen3-14b');
        $first = $this->shownStory(3);
        $second = $this->shownStory(3);
        $this->chat()->queueFailure(new RetryableProviderException(Response::HTTP_TOO_MANY_REQUESTS, 20));

        $this->sweep()->runDue();

        self::assertNull($this->titleColumnsOf($first)['llm_title_attempted_at']);
        self::assertNull($this->titleColumnsOf($second)['llm_title_attempted_at']);
        self::assertCount(1, $this->chat()->calls());
    }

    public function testAFailedCallIsStampedAndCounted(): void
    {
        $this->runFixtures->seedReadyAiSettingsFor($this->reader, 'qwen3-14b');
        $story = $this->shownStory(3);
        $this->chat()->queueFailure(new ProviderUnreachableException('gone'));

        $report = $this->sweep()->runDue();

        self::assertSame(['usable' => 0, 'unusable' => 0, 'failed' => 1], $report->toLogContext());
        self::assertNull($this->titleColumnsOf($story)['llm_title']);
        self::assertSame(self::NOW, $this->titleColumnsOf($story)['llm_title_attempted_at']);
    }

    public function testAScoringConnectionWritesNoTitle(): void
    {
        $this->runFixtures->seedReadyScoringSettings($this->reader);
        $this->shownStory(3);

        $this->sweep()->runDue();

        self::assertCount(0, $this->chat()->calls());
        self::assertSame(0, $this->entityManager->getRepository(StoryAiRun::class)->count());
    }

    public function testSwitchedOffTitlesWriteNothing(): void
    {
        $this->runFixtures->seedReadyAiSettingsFor($this->reader, 'qwen3-14b');
        $settings = new StorySettings($this->reader);
        $settings->update(null, false);
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
        $this->shownStory(3);

        $this->sweep()->runDue();

        self::assertCount(0, $this->chat()->calls());
    }

    public function testAStoryTheReaderIsNotShownGetsNoTitle(): void
    {
        $this->runFixtures->seedReadyAiSettingsFor($this->reader, 'qwen3-14b');
        $this->shownStory(1);

        $this->sweep()->runDue();

        self::assertCount(0, $this->chat()->calls());
    }

    public function testAStoryOutsideEveryWindowIsNotDue(): void
    {
        $this->runFixtures->seedReadyAiSettingsFor($this->reader, 'qwen3-14b');
        $this->storyLastSeen(self::BEFORE_THE_WEEK, 3);

        $this->sweep()->runDue();

        self::assertCount(0, $this->chat()->calls());
    }

    public function testAReaderWithOnlyStoriesOutsideEveryWindowIsNotRanked(): void
    {
        $this->storyLastSeen(self::BEFORE_THE_WEEK, 3);
        $queries = $this->queries();
        $queries->reset();

        $due = $this->task()->dueStoryIds($this->reader, $this->clock->now());

        self::assertSame([], $due);
        self::assertSame([], $this->rankingQueriesIn($queries));
    }

    public function testTheTitleInputIsCleaned(): void
    {
        $this->runFixtures->seedReadyAiSettingsFor($this->reader, 'qwen3-14b');
        $spiegel = $this->fixtures->feed('spiegel');
        $spiegel->setTitle('DER SPIEGEL');
        $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 10:00:00',
            $this->fixtures->entry($spiegel, 'Liveblog: Hafenstreik – Spiegel', '2031-05-14 09:30:00'),
            $this->outletEntry('Hamburg: Terminals stehen still'),
        );
        $this->chat()->queueContent('{"title":"Hafenstreik in Hamburg"}');

        $this->sweep()->runDue();

        self::assertStringContainsString('1. Hafenstreik', $this->userMessageOf(0));
        self::assertStringNotContainsString('Spiegel', $this->userMessageOf(0));
    }

    private function shownStory(int $outlets, ?string $summary = null): TrendingStory
    {
        return $this->storyLastSeen('2031-05-14 10:00:00', $outlets, $summary);
    }

    private function storyLastSeen(string $lastSeen, int $outlets, ?string $summary = null): TrendingStory
    {
        return $this->fixtures->story(
            TrendingWindow::Day,
            $lastSeen,
            ...array_map(
                fn (int $variant): Entry => $this->outletEntry(
                    'Hafenstreik legt Hamburg lahm, Teil ' . $variant,
                    $summary,
                ),
                range(1, $outlets),
            ),
        );
    }

    private function outletEntry(string $headline, ?string $summary = null): Entry
    {
        $this->feedCount++;
        $entry = $this->fixtures->entry(
            $this->fixtures->feed('title-outlet-' . $this->feedCount),
            $headline,
            \sprintf('2031-05-14 09:%02d:00', $this->feedCount % 30),
        );
        if ($summary !== null) {
            $this->entityManager->flush();
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE entry SET summary = ? WHERE id = ?',
                [$summary, $entry->requireId()],
            );
        }

        return $entry;
    }

    private function storeTitle(TrendingStory $story, string $title, int $memberCount, string $attemptedAt): void
    {
        $this->entityManager->getConnection()->update(
            'trending_story',
            ['llm_title' => $title, 'llm_title_member_count' => $memberCount, 'llm_title_attempted_at' => $attemptedAt],
            ['id' => $story->requireId()],
        );
    }

    /**
     * @return array{
     *     llm_title: ?string,
     *     llm_synopsis: ?string,
     *     llm_title_member_count: ?int,
     *     llm_title_attempted_at: ?string,
     * }
     */
    private function titleColumnsOf(TrendingStory $story): array
    {
        /** @var array{llm_title: ?string, llm_synopsis: ?string, llm_title_member_count: int|string|null, llm_title_attempted_at: ?string} $row */
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT llm_title, llm_synopsis, llm_title_member_count, llm_title_attempted_at'
            . ' FROM trending_story WHERE id = ?',
            [$story->requireId()],
        );

        return [
            'llm_title' => $row['llm_title'],
            'llm_synopsis' => $row['llm_synopsis'],
            'llm_title_member_count' => $row['llm_title_member_count'] === null
                ? null
                : (int) $row['llm_title_member_count'],
            'llm_title_attempted_at' => $row['llm_title_attempted_at'],
        ];
    }

    private function userMessageOf(int $call): string
    {
        $messages = $this->chat()->calls()[$call]['messages'];

        return $messages[\count($messages) - 1]['content'];
    }

    private function task(): StoryTitleTask
    {
        /** @var StoryTitleTask $task */
        $task = self::getContainer()->get(StoryTitleTask::class);

        return $task;
    }

    /** @return list<string> */
    private function rankingQueriesIn(DebugDataHolder $queries): array
    {
        /** @var list<array{sql: string}> $statements */
        $statements = $queries->getData()['default'] ?? [];

        return array_values(array_filter(
            array_column($statements, 'sql'),
            static fn (string $sql): bool => str_contains($sql, 'trending_story_hide'),
        ));
    }

    private function queries(): DebugDataHolder
    {
        /** @var DebugDataHolder $queries */
        $queries = self::getContainer()->get('doctrine.debug_data_holder');

        return $queries;
    }

    private function sweep(): StoryAiSweep
    {
        /** @var StoryAiSweep $sweep */
        $sweep = self::getContainer()->get(StoryAiSweep::class);

        return $sweep;
    }

    private function chat(): StubChatClient
    {
        /** @var StubChatClient $chat */
        $chat = self::getContainer()->get(StubChatClient::class);

        return $chat;
    }
}
