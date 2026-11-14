<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\TrendingStoryModel;
use App\Service\Curation\Trending\StoryLinkSource\HeadlineLinks;
use App\Service\Curation\Trending\TrendingExplainer;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;
use App\Tests\Support\TrendingWiring;
use Symfony\Component\Clock\MockClock;

final class TrendingExplainerTest extends DbTestCase
{
    public function testGroupsComeWidestFirstThenNewest(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $this->shareAnArticle($fixtures, 'Rentenreform', 'https://news.example.com/older', '2031-05-14 07:00:00', 2);
        $this->shareAnArticle($fixtures, 'Waldbrand', 'https://news.example.com/widest', '2031-05-14 06:00:00', 3);
        $this->shareAnArticle($fixtures, 'Pokalfinale', 'https://news.example.com/newer', '2031-05-14 09:00:00', 2);
        $fixtures->flush();

        $explanation = $this->explainer()->explain(TrendingWindow::Day);

        self::assertSame(7, $explanation->entryCount);
        self::assertSame(
            [[3, '06:00'], [2, '09:00'], [2, '07:00']],
            array_map(
                static fn (TrendingStoryModel $group): array => [
                    $group->outletCount(),
                    $group->lastSeen->format('H:i'),
                ],
                $explanation->groups,
            ),
        );
    }

    private function shareAnArticle(
        TrendingFixtures $fixtures,
        string $title,
        string $url,
        string $date,
        int $outlets,
    ): void {
        for ($outlet = 0; $outlet < $outlets; ++$outlet) {
            $fixtures->article($fixtures->feed(md5($url) . $outlet), $title, $date, $url);
        }
    }

    private function explainer(): TrendingExplainer
    {
        return new TrendingExplainer(
            TrendingWiring::grouping($this->entityManager, new MockClock('2031-05-14 12:00:00', 'UTC')),
            new HeadlineLinks(),
        );
    }
}
