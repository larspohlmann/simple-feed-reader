<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\UrlGuard;
use App\Service\Fetch\UrlResolver;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Downloads the bytes of one already-resolved icon URL, under the same guards as
 * the feed fetch path (see ConcurrentFeedFetcher::send()): the shared UrlGuard
 * for SSRF — re-run on every redirect hop, never delegated to the HTTP client's
 * own follower — a connection pinned to the guard-validated IP, a bounded
 * redirect chain, a timeout, a wire-level and buffered size cap, transparent
 * compression disabled, and an allow-list of image content types.
 *
 * Resolution — a site homepage to its best icon URL — is NOT this class's job.
 * The warmer resolves a whole slice at once through the shared, concurrent
 * `FaviconResolver::resolveAll()` (see #116) and hands each URL here to download.
 *
 * Invoked ONLY by the warmer. No request path fetches an icon.
 *
 * Not `final`: the warmer's tests double this collaborator, and there is no
 * fetcher interface to mock instead. `readonly` still holds the immutability
 * guarantee that matters.
 */
readonly class CatalogFaviconFetcher
{
    public const int MAX_BYTES = 262144;

    private const int TIMEOUT_SECONDS = 8;
    private const int MAX_REDIRECTS = 3;
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /** Formats a browser will render in an <img>. SVG is excluded deliberately:
     *  it is a script-carrying document format, and we serve these bytes back
     *  from our own origin. */
    private const array ALLOWED_TYPES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/x-icon',
        'image/vnd.microsoft.icon',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private UrlGuard $urlGuard,
    ) {
    }

    public function download(string $iconUrl): FetchedFavicon
    {
        try {
            [$bytes, $contentType] = $this->fetchFollowingRedirects($iconUrl);
        } catch (FetchException | TransportExceptionInterface $e) {
            throw new FaviconUnavailableException($e->getMessage(), 0, $e);
        }

        $this->assertNotEmpty($bytes);
        $this->assertWithinSizeCap($bytes);

        return new FetchedFavicon($iconUrl, $bytes, $contentType);
    }

    /**
     * Re-guards every hop through UrlGuard::assertSafe() before following it —
     * the HTTP client's own redirect handling is disabled ('max_redirects' => 0)
     * because it would resolve DNS itself and never consult the guard, letting a
     * redirect to a private address slip the SSRF boundary entirely.
     *
     * @return array{0: string, 1: string} the body bytes and their content type
     */
    private function fetchFollowingRedirects(string $url): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $response = $this->requestGuarded($url);
            $status = $response->getStatusCode();

            if (!\in_array($status, self::REDIRECT_STATUSES, true)) {
                return $this->readSuccessfulResponse($response, $status);
            }

            $url = UrlResolver::resolve($url, $this->redirectLocation($response));
        }

        throw new FaviconUnavailableException('Icon exceeded ' . self::MAX_REDIRECTS . ' redirects.');
    }

    private function requestGuarded(string $url): ResponseInterface
    {
        $guarded = $this->urlGuard->assertSafe($url);

        return $this->httpClient->request('GET', $url, [
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            // Redirects are followed manually above, one re-guarded hop at a
            // time — never by the client itself.
            'max_redirects' => 0,
            // Pins the connection to the IP the guard just validated, closing
            // the DNS-rebinding window between assertSafe() and the request.
            'resolve' => [$guarded->host => $guarded->ip],
            // Refuse transparent compression so the wire cap below also bounds
            // the buffered body — a compressed response would otherwise
            // decompress unbounded before the size check runs.
            'headers' => ['Accept-Encoding' => 'identity'],
            'on_progress' => static function (int $downloaded): void {
                ResponseTooLargeException::throwIfExceeded($downloaded);
            },
        ]);
    }

    private function redirectLocation(ResponseInterface $response): string
    {
        return $response->getHeaders(false)['location'][0]
            ?? throw new FaviconUnavailableException('Redirect response carried no Location header.');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function readSuccessfulResponse(ResponseInterface $response, int $status): array
    {
        if (200 !== $status) {
            throw new FaviconUnavailableException('Icon responded ' . $status . '.');
        }

        $contentType = $this->assertAllowedType($response->getHeaders(false));

        return [$response->getContent(), $contentType];
    }

    private function assertNotEmpty(string $bytes): void
    {
        if ('' === $bytes) {
            throw new FaviconUnavailableException('Icon body was empty.');
        }
    }

    private function assertWithinSizeCap(string $bytes): void
    {
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw new FaviconUnavailableException('Icon exceeded ' . self::MAX_BYTES . ' bytes.');
        }
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function assertAllowedType(array $headers): string
    {
        $raw = $headers['content-type'][0] ?? '';
        $type = mb_strtolower(trim(explode(';', $raw)[0]));

        if (!\in_array($type, self::ALLOWED_TYPES, true)) {
            throw new FaviconUnavailableException(\sprintf('Content type "%s" is not an allowed image type.', $type));
        }

        return $type;
    }
}
