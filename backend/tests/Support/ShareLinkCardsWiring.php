<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Clock\NaiveUtcClock;
use App\Service\Fetch\DnsResolver\DnsResolverInterface;
use App\Service\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Fetch\UrlGuard;
use App\Service\LinkCard\EntryCardWriter;
use App\Service\LinkCard\PageCardReader\PageCardReader;
use App\Service\LinkCard\PageCardReader\PageCardReaderInterface;
use App\Service\LinkCard\ShareLinkCards;
use App\Service\Reader\PageHeadFetcher;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Service\Search\EntryIndexer;
use App\Service\Search\Index\SearchIndexWriter\SearchIndexWriterInterface;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** ShareLinkCards over a test HTTP client or a stubbed page-card reader, wired as the container wires it. */
final class ShareLinkCardsWiring
{
    private function __construct()
    {
    }

    public static function build(
        EntityManagerInterface $entityManager,
        HttpClientInterface $client,
        ClockInterface $clock,
        SearchIndexWriterInterface $indexWriter = new RecordingSearchIndexWriter(),
    ): ShareLinkCards {
        return self::reading(
            new PageCardReader(self::headFetcher($client)),
            $entityManager,
            $clock,
            indexWriter: $indexWriter,
        );
    }

    public static function reading(
        PageCardReaderInterface $reader,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        LoggerInterface $logger = new NullLogger(),
        SearchIndexWriterInterface $indexWriter = new RecordingSearchIndexWriter(),
    ): ShareLinkCards {
        return new ShareLinkCards(
            $reader,
            self::cardWriter($entityManager),
            PostEnrichers::enrichments($entityManager),
            PostEnrichers::linkCards($entityManager),
            $entityManager,
            new EntryIndexer($indexWriter, new NullLogger()),
            new NaiveUtcClock($clock),
            $logger,
        );
    }

    public static function cardWriter(EntityManagerInterface $entityManager): EntryCardWriter
    {
        return new EntryCardWriter(
            PostEnrichers::linkCards($entityManager),
            new EntrySanitizer(new TrailingBlankRemover()),
        );
    }

    /** Answers every URL with an HTML page titled "Card for <url>". */
    public static function titledPages(): MockHttpClient
    {
        return new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse(
            '<html><head><title>Card for ' . $url . '</title></head></html>',
            ['response_headers' => ['content-type' => 'text/html']],
        ));
    }

    public static function headFetcher(HttpClientInterface $client): PageHeadFetcher
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

        return new PageHeadFetcher(FetchWiring::pageLanding(FetchWiring::redirectFollower(
            $client,
            $noEgressProxy,
            new UrlGuard($everyHostIsPublic, new IpValidator()),
        )));
    }
}
