<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\FeedScheduler;
use App\Service\Fetch\HostThrottle;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;

/** A FeedScheduler over an in-memory host throttle: the one assembly the refresh tests share. */
final class FeedSchedulers
{
    public static function build(ClockInterface $clock): FeedScheduler
    {
        return new FeedScheduler($clock, new HostThrottle(new ArrayAdapter(clock: $clock), $clock));
    }
}
