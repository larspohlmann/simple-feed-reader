<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\TrendingStory;
use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Repository\TrendingStoryFollowRepository;
use App\Service\Curation\Trending\Exception\TrendingStoryNotFollowedException;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\TrendingFixtures;

final class TrendingStoryFollowRepositoryTest extends DbTestCase
{
    private TrendingFixtures $fixtures;
    private TrendingStoryFollowRepository $follows;
    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new TrendingFixtures($this->entityManager);
        $this->follows = self::getContainer()->get(TrendingStoryFollowRepository::class);
        $this->reader = $this->fixtures->reader('follow-repository@example.com');
        $this->fixtures = $this->fixtures->ownedBy($this->reader);
    }

    public function testASecondFollowKeepsTheFirstVisit(): void
    {
        $story = $this->story();

        $this->follows->follow($this->reader->requireId(), $story->requireId(), self::at('2031-05-14 10:00:00'));
        $this->follows->follow($this->reader->requireId(), $story->requireId(), self::at('2031-05-14 12:00:00'));

        self::assertEquals(
            self::at('2031-05-14 10:00:00'),
            $this->follows->lastOpenedAtIfFollowed($this->reader->requireId(), $story->requireId()),
        );
        self::assertSame(
            ['2031-05-14 10:00:00'],
            $this->entityManager->getConnection()->fetchFirstColumn(
                'SELECT followed_at FROM trending_story_follow WHERE user_id = ?',
                [$this->reader->requireId()],
            ),
        );
    }

    public function testMarkingOpenedMovesTheVisitOfThatReaderAndStoryAlone(): void
    {
        $story = $this->story();
        $other = $this->story();
        $otherReader = $this->fixtures->reader('follow-repository-other@example.com');
        foreach ([[$this->reader, $story], [$this->reader, $other], [$otherReader, $story]] as [$user, $followed]) {
            $this->follows->follow($user->requireId(), $followed->requireId(), self::at('2031-05-14 10:00:00'));
        }

        $changed = $this->follows->markOpened(
            $this->reader->requireId(),
            $story->requireId(),
            self::at('2031-05-14 12:00:00'),
        );

        self::assertSame(1, $changed);
        self::assertEquals(
            self::at('2031-05-14 12:00:00'),
            $this->follows->lastOpenedAtIfFollowed($this->reader->requireId(), $story->requireId()),
        );
        self::assertEquals(
            self::at('2031-05-14 10:00:00'),
            $this->follows->lastOpenedAtIfFollowed($this->reader->requireId(), $other->requireId()),
        );
        self::assertEquals(
            self::at('2031-05-14 10:00:00'),
            $this->follows->lastOpenedAtIfFollowed($otherReader->requireId(), $story->requireId()),
        );
    }

    public function testMarkingAStoryNotFollowedOpenedChangesNothing(): void
    {
        $story = $this->story();

        $changed = $this->follows->markOpened(
            $this->reader->requireId(),
            $story->requireId(),
            self::at('2031-05-14 12:00:00'),
        );

        self::assertSame(0, $changed);
    }

    public function testRequiringAStoryNotFollowedIsAnError(): void
    {
        $story = $this->story();

        $this->expectException(TrendingStoryNotFollowedException::class);
        $this->expectExceptionMessage(
            \sprintf('User %d does not follow Trending story %d.', $this->reader->requireId(), $story->requireId()),
        );

        $this->follows->requireFollowed($this->reader->requireId(), $story->requireId());
    }

    public function testRequiringAFollowedStoryPassesForItsFollowerAlone(): void
    {
        $story = $this->story();
        $otherReader = $this->fixtures->reader('follow-repository-require@example.com');
        $this->follows->follow($this->reader->requireId(), $story->requireId(), self::at('2031-05-14 10:00:00'));

        $this->follows->requireFollowed($this->reader->requireId(), $story->requireId());

        $this->expectException(TrendingStoryNotFollowedException::class);
        $this->expectExceptionMessage(
            \sprintf('User %d does not follow Trending story %d.', $otherReader->requireId(), $story->requireId()),
        );
        $this->follows->requireFollowed($otherReader->requireId(), $story->requireId());
    }

    public function testFollowedStoryIdsAreTheReadersAmongTheAsked(): void
    {
        $followed = $this->story();
        $notAsked = $this->story();
        $notFollowed = $this->story();
        $otherReader = $this->fixtures->reader('follow-repository-ids@example.com');
        $this->follows->follow($this->reader->requireId(), $followed->requireId(), self::at('2031-05-14 10:00:00'));
        $this->follows->follow($this->reader->requireId(), $notAsked->requireId(), self::at('2031-05-14 10:00:00'));
        $this->follows->follow($otherReader->requireId(), $notFollowed->requireId(), self::at('2031-05-14 10:00:00'));

        self::assertSame(
            [$followed->requireId()],
            $this->follows->followedStoryIds(
                $this->reader->requireId(),
                [$followed->requireId(), $notFollowed->requireId()],
            ),
        );
    }

    public function testAskingForNoStoriesRunsNoQuery(): void
    {
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        self::assertSame([], $this->follows->followedStoryIds($this->reader->requireId(), []));
        self::assertSame([], $recorder->queriesMatching('trending_story_follow'));
    }

    public function testUnfollowingRemovesThatReadersFollowAlone(): void
    {
        $story = $this->story();
        $otherReader = $this->fixtures->reader('follow-repository-unfollow@example.com');
        $this->follows->follow($this->reader->requireId(), $story->requireId(), self::at('2031-05-14 10:00:00'));
        $this->follows->follow($otherReader->requireId(), $story->requireId(), self::at('2031-05-14 10:00:00'));

        $this->follows->unfollow($this->reader->requireId(), $story->requireId());

        self::assertSame([], $this->follows->followedStoryIds($this->reader->requireId(), [$story->requireId()]));
        self::assertSame(
            [$story->requireId()],
            $this->follows->followedStoryIds($otherReader->requireId(), [$story->requireId()]),
        );
    }

    public function testAFollowedStoryIsHeadedByItsTitle(): void
    {
        $story = $this->followedStoryNewestHeadlinedNeueGespraeche();
        $this->storeTitles($story, null, 'Hafenstreik in Hamburg');

        self::assertSame('Hafenstreik in Hamburg', $this->follows->followedBy($this->reader->requireId())[0]->headline);
    }

    public function testTheLlmTitleHeadsAFollowedStory(): void
    {
        $story = $this->followedStoryNewestHeadlinedNeueGespraeche();
        $this->storeTitles($story, 'Streik legt den Hafen lahm', 'Hafenstreik in Hamburg');

        self::assertSame(
            'Streik legt den Hafen lahm',
            $this->follows->followedBy($this->reader->requireId())[0]->headline,
        );
    }

    public function testAStoryWithoutStoredTitlesKeepsTheNewestMemberHeadline(): void
    {
        $this->followedStoryNewestHeadlinedNeueGespraeche();

        self::assertSame('Neue Gespräche', $this->follows->followedBy($this->reader->requireId())[0]->headline);
    }

    private function followedStoryNewestHeadlinedNeueGespraeche(): TrendingStory
    {
        $feed = $this->fixtures->feed(bin2hex(random_bytes(4)));
        $this->fixtures->subscribe($this->reader, $feed);
        $story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $this->fixtures->entry($feed, 'Hafenstreik', '2031-05-14 08:00:00'),
            $this->fixtures->entry($feed, 'Neue Gespräche', '2031-05-14 09:00:00'),
        );
        $this->fixtures->follow($this->reader, $story, '2031-05-14 10:00:00');

        return $story;
    }

    private function storeTitles(TrendingStory $story, ?string $llmTitle, string $fallbackTitle): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE trending_story SET llm_title = ?, fallback_title = ? WHERE id = ?',
            [$llmTitle, $fallbackTitle, $story->requireId()],
        );
    }

    private function story(): TrendingStory
    {
        $feed = $this->fixtures->feed(bin2hex(random_bytes(4)));

        return $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $this->fixtures->entry($feed, 'Story', '2031-05-14 09:00:00'),
        );
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }
}
