<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\UrlGuard;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads the bytes of one already-resolved icon URL, under the same guards as
 * the feed fetch path: the shared UrlGuard for SSRF, a bounded redirect chain, a
 * timeout, a size cap and an allow-list of image content types.
 *
 * Resolution — a site homepage to its best icon URL — is NOT this class's job.
 * The warmer resolves a whole slice at once through the shared, concurrent
 * `FaviconResolver::resolveAll()` (see #116) and hands each URL here to download.
 *
 * Invoked ONLY by the warmer. No request path fetches an icon.
 */
final readonly class CatalogFaviconFetcher
{
    public const int MAX_BYTES = 262144;

    private const int TIMEOUT_SECONDS = 8;
    private const int MAX_REDIRECTS = 3;

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
            $this->urlGuard->assertSafe($iconUrl);
            $response = $this->httpClient->request('GET', $iconUrl, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'max_redirects' => self::MAX_REDIRECTS,
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new FaviconUnavailableException('Icon responded ' . $response->getStatusCode() . '.');
            }

            $contentType = $this->assertAllowedType($response->getHeaders(false));
            $bytes = $response->getContent();
        } catch (SsrfBlockedException | TransportException $e) {
            throw new FaviconUnavailableException($e->getMessage(), 0, $e);
        }

        if ('' === $bytes) {
            throw new FaviconUnavailableException('Icon body was empty.');
        }
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw new FaviconUnavailableException('Icon exceeded ' . self::MAX_BYTES . ' bytes.');
        }

        return new FetchedFavicon($iconUrl, $bytes, $contentType);
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
