<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\LinkCard;
use App\Http\SymfonyStatusReasonPhrases;
use App\Repository\EntryRepository;
use App\Repository\LinkCardRepository;
use App\Repository\TriedPostRepository;
use App\Service\Fetch\DnsResolver\DnsResolverInterface;
use App\Service\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Fetch\UrlGuard;
use App\Service\LinkCard\PageCardReader;
use App\Service\LinkCard\PostCardWriter;
use App\Service\LinkCard\PostLinkCards;
use App\Service\Reader\HtmlPageFetcher;
use App\Service\Reader\LandingChallenge;
use App\Service\Reader\MetaRefreshTarget;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** PostLinkCards over a test HTTP client and an in-memory tried-marker pool, wired as the container wires it. */
final class PostLinkCardsWiring
{
    private function __construct()
    {
    }

    public static function build(
        EntityManagerInterface $entityManager,
        HttpClientInterface $client,
        LoggerInterface $logger,
    ): PostLinkCards {
        return self::assemble($entityManager, $client, $logger, new TriedPostRepository(new ArrayAdapter()));
    }

    /** For a test that reads the markers back through the container, as the list's pending flag does. */
    public static function sharingTriedPosts(
        EntityManagerInterface $entityManager,
        HttpClientInterface $client,
        TriedPostRepository $triedPosts,
    ): PostLinkCards {
        return self::assemble($entityManager, $client, new NullLogger(), $triedPosts);
    }

    /** For refresh tests that build no cards: every page fetch fails before it reaches a network. */
    public static function offline(EntityManagerInterface $entityManager): PostLinkCards
    {
        return self::build($entityManager, new MockHttpClient([]), new NullLogger());
    }

    private static function assemble(
        EntityManagerInterface $entityManager,
        HttpClientInterface $client,
        LoggerInterface $logger,
        TriedPostRepository $triedPosts,
    ): PostLinkCards {
        /** @var EntryRepository $entries */
        $entries = $entityManager->getRepository(Entry::class);
        /** @var LinkCardRepository $linkCards */
        $linkCards = $entityManager->getRepository(LinkCard::class);

        return new PostLinkCards(
            new PostCardWriter(
                new PageCardReader(self::pageFetcher($client)),
                new EntrySanitizer(new TrailingBlankRemover()),
                $linkCards,
                $entityManager,
                $logger,
            ),
            $triedPosts,
            $entries,
            $linkCards,
            $entityManager,
            $logger,
        );
    }

    private static function pageFetcher(HttpClientInterface $client): HtmlPageFetcher
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
