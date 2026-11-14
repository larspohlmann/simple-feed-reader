<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\StoryLinkModel;
use App\Tests\Support\TrendingRuns;
use App\Tests\Support\TrendingWiring;
use PHPUnit\Framework\TestCase;

final class StoryLinksTest extends TestCase
{
    public function testAPairBothSourcesLinkIsKeptOnceAtTheUrlStrength(): void
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        $run->add(TrendingRuns::entry(1, 10, TrendingRuns::VOLCANO_HEADLINE, '2031-05-14 08:00:00'), ['hash-a']);
        $run->add(TrendingRuns::entry(2, 20, TrendingRuns::VOLCANO_REPLY_HEADLINE, '2031-05-14 09:00:00'), ['hash-a']);
        $run->add(TrendingRuns::entry(3, 30, 'Island Vulkan Ausbruch live', '2031-05-14 09:30:00'), []);

        $links = TrendingWiring::storyLinks()->between($run);
        $strengthByPair = [];
        foreach ($links as $link) {
            $strengthByPair[$link->pairKey()] = $link->strength;
        }
        ksort($strengthByPair);

        self::assertSame(['1:2', '1:3', '2:3'], array_keys($strengthByPair));
        $urlStrength = StoryLinkModel::sameUrl(1, 2)->strength;
        self::assertSame($urlStrength, $strengthByPair['1:2']);
        self::assertLessThan($urlStrength, $strengthByPair['1:3']);
    }
}
