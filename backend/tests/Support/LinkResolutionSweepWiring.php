<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\SharedLinkRepository;
use App\Repository\SharedLinkResolutionRepository;
use App\Service\Content\LinkResolution\LinkResolutionSweep;
use App\Service\Foundation\Clock\NaiveUtcClock;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LinkResolutionSweepWiring
{
    private function __construct()
    {
    }

    /** @param list<MockResponse> $responses */
    public static function build(
        ContainerInterface $container,
        LinkLandingWiring $wiring,
        ClockInterface $clock,
        array $responses = [],
    ): LinkResolutionSweep {
        /** @var SharedLinkResolutionRepository $linkResolutions */
        $linkResolutions = $container->get(SharedLinkResolutionRepository::class);

        /** @var SharedLinkRepository $sharedLinks */
        $sharedLinks = $container->get(SharedLinkRepository::class);

        return new LinkResolutionSweep(
            $linkResolutions,
            $sharedLinks,
            $wiring->landing($responses),
            new NaiveUtcClock($clock),
            new NullLogger(),
        );
    }
}
