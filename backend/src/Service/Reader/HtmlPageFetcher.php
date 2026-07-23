<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\GuardedUrl;
use App\Service\Fetch\UrlGuard;
use App\Service\Fetch\UrlResolver;
use App\Service\Reader\Exception\PageFetchException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Retrieves an article's source HTML for reader-mode extraction. Structurally a
 * sibling of HttpFeedFetcher — same SSRF-guarded, per-hop-revalidated redirect
 * loop and byte cap — but returns the decoded body plus the final URL (readability
 * needs it to resolve relative image URLs) and negotiates HTML, not feed XML.
 */
final class HtmlPageFetcher
{
    private const MAX_REDIRECTS = 5;
    private const MAX_BYTES = 3_000_000;
    private const TIMEOUT_SECONDS = 10.0;
    private const USER_AGENT = 'SimpleFeedReader/1.0 (+https://github.com/larspohlmann/simple-feed-reader)';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UrlGuard $urlGuard,
    ) {
    }

    public function fetch(string $url): PageResponse
    {
        $currentUrl = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $guarded = $this->urlGuard->assertSafe($currentUrl);
            } catch (SsrfBlockedException $e) {
                throw new PageFetchException($e->getMessage(), previous: $e);
            }

            $response = $this->request($currentUrl, $guarded);
            $status = $this->statusCode($response, $currentUrl);

            if (\in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $this->header($response, 'location');
                $response->cancel();
                if ($location === null) {
                    throw new PageFetchException(sprintf('%s: redirect without Location', $currentUrl));
                }
                $currentUrl = UrlResolver::resolve($currentUrl, $location);
                continue;
            }

            if ($status < 200 || $status >= 300) {
                $response->cancel();

                throw new PageFetchException(sprintf('%s: HTTP %d', $currentUrl, $status));
            }

            $body = $this->content($response, $currentUrl);
            if (\strlen($body) > self::MAX_BYTES) {
                throw new PageFetchException(sprintf('%s: response exceeds %d bytes', $currentUrl, self::MAX_BYTES));
            }

            return new PageResponse($currentUrl, $body);
        }

        throw new PageFetchException(sprintf('%s: more than %d redirects', $url, self::MAX_REDIRECTS));
    }

    private function request(string $url, GuardedUrl $guarded): ResponseInterface
    {
        try {
            return $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    // Refuse transparent compression: otherwise curl counts the
                    // COMPRESSED bytes against MAX_BYTES in on_progress but buffers
                    // the DECOMPRESSED body whole before the post-read size check —
                    // a small gzip bomb could inflate to GB and OOM the worker.
                    'Accept-Encoding' => 'identity',
                    'User-Agent' => self::USER_AGENT,
                ],
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS * 2,
                'resolve' => [$guarded->host => $guarded->ip],
                'on_progress' => static function (int $downloaded): void {
                    if ($downloaded > self::MAX_BYTES) {
                        throw new PageFetchException(sprintf('response exceeds %d bytes', self::MAX_BYTES));
                    }
                },
            ]);
        } catch (ExceptionInterface $e) {
            throw new PageFetchException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function statusCode(ResponseInterface $response, string $url): int
    {
        try {
            return $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new PageFetchException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function content(ResponseInterface $response, string $url): string
    {
        try {
            return $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new PageFetchException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function header(ResponseInterface $response, string $name): ?string
    {
        try {
            return $response->getHeaders(false)[$name][0] ?? null;
        } catch (ExceptionInterface) {
            return null;
        }
    }
}
