<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Clock\NaiveUtcClock;
use App\Service\LinkCard\LinkCardSweep;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** LinkCardSweep over a test HTTP client, wired as the container wires it. */
final class LinkCardSweepWiring
{
    private function __construct()
    {
    }

    public static function build(
        EntityManagerInterface $entityManager,
        HttpClientInterface $client,
        ClockInterface $clock,
    ): LinkCardSweep {
        return new LinkCardSweep(
            PostEnrichers::enrichments($entityManager),
            ShareLinkCardsWiring::build($entityManager, $client, $clock),
            new NaiveUtcClock($clock),
        );
    }
}
