<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Pass;

use App\Service\Social\Pass\TimelineAccounts;
use App\Service\Social\Pass\TimelineRound;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class TimelineRoundTest extends TestCase
{
    public function testAKeyThatWasNeverTicketedIsALogicError(): void
    {
        $sources = SocialApiWiring::timelineSources(new MockHttpClient(), new ArrayAdapter());
        $round = new TimelineRound([], $sources, new TimelineAccounts());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Feed 999 was never ticketed in this round.');

        $round->admits(999);
    }
}
