<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Pass;

use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Model\TimelineSlotsModel;
use App\Service\Social\Pass\TimelineRound;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class TimelineRoundTest extends TestCase
{
    public function testAFeedWithoutItsSocialFeedIsOrphaned(): void
    {
        $sources = SocialApiWiring::timelineSources(new MockHttpClient(), new ArrayAdapter());
        $round = new TimelineRound($sources, new TimelineSlotsModel(3, 4), []);

        $this->expectException(PrivateFeedOrphanedException::class);

        $round->admits(999);
    }
}
