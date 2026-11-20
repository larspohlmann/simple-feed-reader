<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\FaviconResolver;

use App\Service\Foundation\Url\Support\HttpsImageUrl;
use App\Service\Outbound\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Outbound\Fetch\Model\FetchTicketModel;
use App\Service\Outbound\Fetch\Pass\PageUrls;
use App\Service\Outbound\Fetch\Support\LemmySiteIcon;
use Psr\Log\LoggerInterface;

/**
 * Best-effort favicons for a batch of feeds' sites: the largest https icon each homepage's <link> tags advertise,
 * else a Lemmy instance's own site icon, else /favicon.ico. Never throws: a favicon must not disturb the refresh that
 * asked for it.
 */
final readonly class FaviconResolver implements FaviconResolverInterface
{
    private const int URL_MAX = 2048;

    public function __construct(
        private BatchFeedFetcherInterface $fetcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int, string> $baseUrlsByFeedId a feed's siteUrl, or its feed URL when it has none
     *
     * @return array<int, string|null> an https icon URL per key; null when the URL carries no host
     */
    public function resolveAll(array $baseUrlsByFeedId): array
    {
        $origins = [];
        $icons = [];
        foreach ($baseUrlsByFeedId as $feedId => $baseUrl) {
            $origin = self::httpsOrigin($baseUrl);
            if (null === $origin) {
                $icons[$feedId] = null;
                continue;
            }
            $origins[$feedId] = $origin;
        }

        return $icons + $this->fetchAllIcons($origins);
    }

    /**
     * @param array<int, string> $origins
     *
     * @return array<int, string>
     */
    private function fetchAllIcons(array $origins): array
    {
        $iconsByOrigin = $this->fetchIconsByOrigin($origins);

        return array_map(
            static fn (string $origin): string => mb_substr(
                $iconsByOrigin[$origin] ?? $origin . '/favicon.ico',
                0,
                self::URL_MAX,
            ),
            $origins,
        );
    }

    /**
     * One ticket per origin, however many feeds share it. Catches even the batch fetcher's own invariant failures,
     * which a best-effort component does not propagate; a site the batch never answered for is simply absent.
     *
     * @param array<int, string> $origins
     *
     * @return array<string, string>
     */
    private function fetchIconsByOrigin(array $origins): array
    {
        $icons = [];
        $tickets = [];
        foreach ($origins as $origin) {
            $tickets[$origin] = new FetchTicketModel($origin);
        }

        try {
            foreach ($this->fetcher->fetchAll($tickets) as $origin => $outcome) {
                $origin = (string) $origin;
                $icon = $this->tryFindIcon($outcome, $origin);
                if (null !== $icon) {
                    $icons[$origin] = $icon;
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Favicon batch fetch failed', ['exception' => $exception]);
        }

        return $icons;
    }

    private function tryFindIcon(FetchOutcomeModel $outcome, string $origin): ?string
    {
        try {
            return $this->iconFrom($outcome, $origin);
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'Favicon parse failed for {origin}',
                ['origin' => $origin, 'exception' => $exception],
            );

            return null;
        }
    }

    private function iconFrom(FetchOutcomeModel $outcome, string $origin): ?string
    {
        $failure = $outcome->failure();
        if (null !== $failure) {
            $this->logger->info('Favicon fetch failed for {origin}', ['origin' => $origin, 'exception' => $failure]);

            return null;
        }

        $response = $outcome->responseOrThrow();
        $body = $response->modifiedBody();
        if ('' === $body) {
            return null;
        }

        return $this->pickIcon($body, new PageUrls($response->finalUrl)) ?? LemmySiteIcon::in($body);
    }

    private function pickIcon(string $html, PageUrls $pageUrls): ?string
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: never let the parser dereference external entities.
        $document->loadHTML($html, \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $best = null;
        $bestSize = -1;
        foreach ($document->getElementsByTagName('link') as $link) {
            // Matches "icon", "shortcut icon" and "apple-touch-icon".
            if (!str_contains(strtolower(trim($link->getAttribute('rel'))), 'icon')) {
                continue;
            }
            $resolved = HttpsImageUrl::orNull($pageUrls->httpUrl($link->getAttribute('href')));
            if (null === $resolved) {
                continue;
            }

            $size = self::largestSize($link->getAttribute('sizes'));
            if ($size > $bestSize) {
                $bestSize = $size;
                $best = $resolved;
            }
        }

        return $best;
    }

    /**
     * The largest edge declared in a `sizes` attribute ("32x32 16x16" -> 32).
     * A scalable icon ("any", typically SVG) outranks any raster size; an absent
     * or unparseable attribute scores 0 so a sized icon always wins over it.
     */
    private static function largestSize(string $sizes): int
    {
        $sizes = strtolower(trim($sizes));
        if ('' === $sizes) {
            return 0;
        }
        if (str_contains($sizes, 'any')) {
            return \PHP_INT_MAX;
        }

        $largest = 0;
        foreach (preg_split('/\s+/', $sizes) ?: [] as $token) {
            if (1 === preg_match('/^(\d+)x\d+$/', $token, $matches)) {
                $largest = max($largest, (int) $matches[1]);
            }
        }

        return $largest;
    }

    private static function httpsOrigin(string $url): ?string
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return null;
        }

        return 'https://' . $host;
    }
}
