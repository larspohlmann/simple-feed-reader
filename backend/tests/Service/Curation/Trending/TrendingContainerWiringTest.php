<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Enum\FeedLanguage;
use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\GermanLexicon\BundledGermanLexicon;
use App\Service\Curation\Trending\GermanLexicon\GermanLexiconInterface;
use App\Service\Curation\Trending\HeadlineEquivalents;
use App\Service\Curation\Trending\SnapshotBuilder\TrendingSnapshotBuilder;
use App\Service\Curation\Trending\StoryBuildPolicy\DayStoryBuildPolicy;
use App\Service\Curation\Trending\StoryBuildPolicy\WeekStoryBuildPolicy;
use App\Service\Curation\Trending\TrendingRebuild;
use App\Service\Foundation\Text\StopWords\BundledStopWords;
use App\Service\Foundation\Text\StopWords\StopWordsInterface;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;

/** The container's own rebuild finds both link sources through the tag: one story by URL, one by headline. */
final class TrendingContainerWiringTest extends DbTestCase
{
    public function testTheContainersRebuildLinksByUrlAndByHeadline(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $hourAgo = (new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        foreach (['alpha', 'beta', 'gamma'] as $variant => $name) {
            $fixtures->hafenstreik($fixtures->feed($name), $variant, $hourAgo);
        }
        foreach (['ann', 'ben', 'cat'] as $index => $handle) {
            $fixtures->postSharing(
                $fixtures->feed($handle),
                ['Lesenswert', 'Wichtig!', 'Seht euch das an'][$index],
                $hourAgo,
                'https://news.example.com/wiring',
            );
        }
        $fixtures->owner()->setLastLoginAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $fixtures->flush();
        $rebuild = self::getContainer()->get(TrendingRebuild::class);
        self::assertInstanceOf(TrendingRebuild::class, $rebuild);

        $report = $rebuild->rebuildDue();

        self::assertSame(2, $report->builds[0]->storyCount);
    }

    public function testEachWindowHasABuildPolicy(): void
    {
        $builder = self::getContainer()->get(TrendingSnapshotBuilder::class);
        self::assertInstanceOf(TrendingSnapshotBuilder::class, $builder);
        $policyFor = new \ReflectionMethod($builder, 'policyFor');

        self::assertInstanceOf(DayStoryBuildPolicy::class, $policyFor->invoke($builder, TrendingWindow::Day));
        self::assertInstanceOf(WeekStoryBuildPolicy::class, $policyFor->invoke($builder, TrendingWindow::Week));
    }

    public function testTheContainersGermanLexiconIsTheBundledOne(): void
    {
        self::assertInstanceOf(BundledGermanLexicon::class, self::getContainer()->get(GermanLexiconInterface::class));
    }

    public function testTheContainersStopWordsAreTheBundledOnes(): void
    {
        self::assertInstanceOf(BundledStopWords::class, self::getContainer()->get(StopWordsInterface::class));
    }

    public function testTheContainersEquivalentsSplitAGermanCompound(): void
    {
        $equivalents = self::getContainer()->get(HeadlineEquivalents::class);
        self::assertInstanceOf(HeadlineEquivalents::class, $equivalents);
        $stems = $equivalents->stemWordAndParts('polizeieinsatz', 'polizeieinsatz', FeedLanguage::German);

        self::assertContains('polizei', $stems);
    }
}
