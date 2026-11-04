<?php

declare(strict_types=1);

namespace App\Tests\Service\Trending;

use App\Service\Trending\TrendingRebuild;
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
        $fixtures->flush();
        $rebuild = self::getContainer()->get(TrendingRebuild::class);
        self::assertInstanceOf(TrendingRebuild::class, $rebuild);

        $report = $rebuild->rebuildDue();

        self::assertSame(2, $report->builds[0]->storyCount);
    }
}
