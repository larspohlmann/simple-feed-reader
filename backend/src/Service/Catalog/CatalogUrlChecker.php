<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Service\Catalog\Exception\BrokenCatalogUrlException;
use App\Service\Fetch\EgressOptions;
use App\Service\Fetch\ProxyConfig;
use App\Service\Fetch\ProxyEgressResolver;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class CatalogUrlChecker
{
    private const int TIMEOUT_SECONDS = 20;

    public function __construct(
        private HttpClientInterface $httpClient,
        private BundledCatalog $bundled,
        private string $userAgent,
        private ProxyEgressResolver $proxyEgressResolver,
    ) {
    }

    public function check(?int $limit): CatalogUrlReport
    {
        $feeds = $this->feedsToCheck($limit);
        // Once per sweep: the instance proxy cannot change mid-run, and each read costs a row lookup and a decryption.
        $proxy = $this->proxyEgressResolver->resolve();

        $broken = [];
        foreach ($feeds as $feed) {
            try {
                $this->assertServesFeed($feed->url, $proxy);
            } catch (BrokenCatalogUrlException $failure) {
                $broken[] = new BrokenCatalogUrl($feed->title, $feed->url, $failure->getMessage());
            }
        }

        return new CatalogUrlReport(\count($feeds), $broken);
    }

    /** @return list<CatalogDocumentFeed> */
    private function feedsToCheck(?int $limit): array
    {
        $feeds = [];
        foreach ($this->bundled->document()->categories as $category) {
            foreach ($category->feeds as $feed) {
                $feeds[] = $feed;
            }
        }

        return null === $limit ? $feeds : \array_slice($feeds, 0, $limit);
    }

    /** @throws BrokenCatalogUrlException */
    private function assertServesFeed(string $url, ?ProxyConfig $proxy): void
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                // The fetcher's agent: a publisher tolerating an unknown checker must not pass for healthy.
                'headers' => ['User-Agent' => $this->userAgent],
                ...(null !== $proxy ? EgressOptions::proxied($proxy) : []),
            ]);
            $status = $response->getStatusCode();
            $head = 200 === $status ? mb_substr($response->getContent(), 0, 2048) : '';
        } catch (ExceptionInterface $e) {
            throw new BrokenCatalogUrlException($e->getMessage(), 0, $e);
        }

        if (200 !== $status) {
            throw new BrokenCatalogUrlException('HTTP ' . $status);
        }
        if (!str_contains($head, '<rss') && !str_contains($head, '<feed') && !str_contains($head, '<rdf:RDF')) {
            throw new BrokenCatalogUrlException('not a feed document');
        }
    }
}
