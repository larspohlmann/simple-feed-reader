<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialFeed;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\TimelineReader\TimelineReaderInterface;
use PHPUnit\Framework\Assert;

final class StubTimelineReader implements TimelineReaderInterface
{
    /** @var list<SocialFeed> */
    public array $asked = [];

    /** @param array<string, TimelinePageModel|\Throwable> $outcomes by the private feed's URL */
    public function __construct(private readonly array $outcomes = [])
    {
    }

    public function page(SocialFeed $socialFeed): TimelinePageModel
    {
        $this->asked[] = $socialFeed;
        $url = $socialFeed->getFeed()->getUrl();
        $outcome = $this->outcomes[$url] ?? Assert::fail('No timeline page scripted for ' . $url);
        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }

        return $outcome;
    }
}
