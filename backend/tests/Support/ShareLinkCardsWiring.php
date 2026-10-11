<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\SymfonyStatusReasonPhrases;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Fetch\DnsResolver\DnsResolverInterface;
use App\Service\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Fetch\UrlGuard;
use App\Service\LinkCard\PageCardReader;
use App\Service\LinkCard\ShareLinkCards;
use App\Service\Reader\HtmlPageFetcher;
use App\Service\Reader\LandingChallenge;
use App\Service\Reader\MetaRefreshTarget;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** ShareLinkCards over a test HTTP client, wired as the container wires it. */
final class ShareLinkCardsWiring
{
    private function __construct()
    {
    }

    public static function build(
        EntityManagerInterface $entityManager,
        HttpClientInterface $client,
        ClockInterface $clock,
        LoggerInterface $logger = new NullLogger(),
    ): ShareLinkCards {
        return new ShareLinkCards(
            new PageCardReader(self::pageFetcher($client)),
            PostEnrichers::enrichments($entityManager),
            PostEnrichers::linkCards($entityManager),
            $entityManager,
            new NaiveUtcClock($clock),
            new EntrySanitizer(new TrailingBlankRemover()),
            $logger,
        );
    }

    public static function pageFetcher(HttpClientInterface $client): HtmlPageFetcher
    {
        $everyHostIsPublic = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $noEgressProxy = new class implements EgressProxySourceInterface {
            public function egressProxy(): ?ProxyConfigModel
            {
                return null;
            }
        };

        return new HtmlPageFetcher(
            FetchWiring::redirectFollower(
                $client,
                $noEgressProxy,
                new UrlGuard($everyHostIsPublic, new IpValidator()),
            ),
            new MetaRefreshTarget(),
            new LandingChallenge(),
            'TestAgent/1.0',
            new SymfonyStatusReasonPhrases(),
        );
    }
}
