<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class HttpFeedFetcher implements FeedFetcherInterface
{
    private const MAX_REDIRECTS = 5;
    private const MAX_BYTES = 5_000_000;
    private const TIMEOUT_SECONDS = 10.0;
    private const USER_AGENT = 'SimpleFeedReader/1.0 (+https://github.com/larspohlmann/simple-feed-reader)';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UrlGuard $urlGuard,
    ) {
    }

    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResponse
    {
        $currentUrl = $url;
        $permanentRedirect = false;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $guarded = $this->urlGuard->assertSafe($currentUrl);
            $response = $this->request($currentUrl, $guarded, $etag, $lastModified);

            // A terminal FetchResponse ends the loop; a string is the next hop's URL.
            $result = $this->classify($response, $currentUrl, $permanentRedirect, $etag, $lastModified);
            if ($result instanceof FetchResponse) {
                return $result;
            }
            $currentUrl = $result;
        }

        throw new FeedUnreachableException(sprintf('%s: more than %d redirects', $url, self::MAX_REDIRECTS));
    }

    /**
     * Turns one HTTP response into either a terminal FetchResponse or the next
     * URL to follow (a redirect). `$permanentRedirect` is updated by reference so
     * a 301/308 anywhere in the chain is reported on the final response.
     *
     * @return FetchResponse|string
     */
    private function classify(
        ResponseInterface $response,
        string $currentUrl,
        bool &$permanentRedirect,
        ?string $etag,
        ?string $lastModified,
    ): FetchResponse|string {
        $status = $this->statusCode($response, $currentUrl);

        if (\in_array($status, [301, 302, 303, 307, 308], true)) {
            $location = $this->header($response, 'location');
            $response->cancel();
            if ($location === null) {
                throw new FeedUnreachableException(sprintf('%s: redirect without Location header', $currentUrl));
            }
            $permanentRedirect = $permanentRedirect || \in_array($status, [301, 308], true);

            return UrlResolver::resolve($currentUrl, $location);
        }

        if ($status === 304) {
            $response->cancel();

            return FetchResponse::notModified($currentUrl, $permanentRedirect, $etag, $lastModified);
        }

        if ($status === 410) {
            $response->cancel();

            throw new FeedGoneException(sprintf('%s: HTTP 410 Gone', $currentUrl));
        }

        if ($status < 200 || $status >= 300) {
            $response->cancel();

            throw new FeedUnreachableException(sprintf('%s: HTTP %d', $currentUrl, $status), $status);
        }

        $body = $this->content($response, $currentUrl);
        if (\strlen($body) > self::MAX_BYTES) {
            throw new ResponseTooLargeException(
                sprintf('%s: response exceeds %d bytes', $currentUrl, self::MAX_BYTES),
            );
        }

        return FetchResponse::fetched(
            $currentUrl,
            $permanentRedirect,
            $body,
            $this->header($response, 'etag'),
            $this->header($response, 'last-modified'),
        );
    }

    private function request(string $url, GuardedUrl $guarded, ?string $etag, ?string $lastModified): ResponseInterface
    {
        $headers = [
            'Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.1',
            // Refuse transparent compression so the MAX_BYTES cap (counted on the
            // wire in on_progress) also bounds the buffered body — a compressed
            // response would otherwise decompress unbounded before the size check.
            'Accept-Encoding' => 'identity',
            'User-Agent' => self::USER_AGENT,
        ];
        if ($etag !== null) {
            $headers['If-None-Match'] = $etag;
        }
        if ($lastModified !== null) {
            $headers['If-Modified-Since'] = $lastModified;
        }

        try {
            return $this->httpClient->request('GET', $url, [
                'headers' => $headers,
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS * 2,
                'resolve' => [$guarded->host => $guarded->ip],
                'on_progress' => static function (int $downloaded): void {
                    if ($downloaded > self::MAX_BYTES) {
                        throw new ResponseTooLargeException(sprintf('response exceeds %d bytes', self::MAX_BYTES));
                    }
                },
            ]);
        } catch (ExceptionInterface $e) {
            $this->rethrowTooLarge($e);

            throw new FeedUnreachableException(sprintf('%s: %s', $url, $e->getMessage()), null, $e);
        }
    }

    private function statusCode(ResponseInterface $response, string $url): int
    {
        try {
            return $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            $this->rethrowTooLarge($e);

            throw new FeedUnreachableException(sprintf('%s: %s', $url, $e->getMessage()), null, $e);
        }
    }

    private function content(ResponseInterface $response, string $url): string
    {
        try {
            return $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->rethrowTooLarge($e);

            throw new FeedUnreachableException(sprintf('%s: %s', $url, $e->getMessage()), null, $e);
        }
    }

    private function header(ResponseInterface $response, string $name): ?string
    {
        try {
            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface) {
            return null;
        }

        return $headers[$name][0] ?? null;
    }

    /**
     * The HTTP client wraps exceptions thrown inside on_progress; unwrap and
     * rethrow our size-limit exception so callers see the real cause.
     */
    private function rethrowTooLarge(?\Throwable $e): void
    {
        while ($e !== null) {
            if ($e instanceof ResponseTooLargeException) {
                throw $e;
            }
            $e = $e->getPrevious();
        }
    }
}
