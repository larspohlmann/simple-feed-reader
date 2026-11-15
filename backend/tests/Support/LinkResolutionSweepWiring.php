<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\SharedLinkResolutionRepository;
use App\Service\Content\LinkResolution\LinkResolutionSweep;
use App\Service\Foundation\Clock\NaiveUtcClock;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
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
        $linkResolutions = $container->get(SharedLinkResolutionRepository::class);
        \assert($linkResolutions instanceof SharedLinkResolutionRepository);

        return new LinkResolutionSweep($linkResolutions, $wiring->landing($responses), new NaiveUtcClock($clock));
    }
}
