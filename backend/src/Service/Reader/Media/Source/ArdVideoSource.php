<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;

/**
 * ARD's progressive MP4. Public broadcasters depublish on a schedule, and the
 * reader's article cache has no TTL, so the poster is not decoration: it turns
 * eventual depublication into a still with a play control that fails, instead of
 * a black frame. A page that offers no poster yields no candidate.
 *
 * The renditions live in the page's player JSON, which the normalizer's script
 * strip removes — this class reads the raw source, so they are still there.
 */
final readonly class ArdVideoSource implements MediaCandidateSourceInterface
{
    private const array HOSTS = [
        'www.tagesschau.de', 'tagesschau.de', 'www.ndr.de', 'ndr.de',
        'www.daserste.de', 'daserste.de',
    ];

    private const string VIDEO_PATTERN = '#https://[a-z0-9-]+\.ard-mcdn\.de/[^"\'\s\\\\]+?\.mp4#i';

    private const string POSTER_PATTERN = '#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']#i';

    /** Widest first: ARD labels renditions by size, and the largest reads best. */
    private const array RENDITION_ORDER = ['webxxl', 'webxl', 'webl', 'webm', 'webs'];

    public function __construct(private DurableMediaUrl $durable)
    {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        if (!$this->isArd($pageUrl)) {
            return [];
        }

        $poster = $this->poster($pageHtml);
        $video = $poster === null ? null : $this->widestVideo($pageHtml);

        return $video === null ? [] : [new MediaCandidate(MediaKind::Video, $video, $poster)];
    }

    private function isArd(string $pageUrl): bool
    {
        $host = parse_url($pageUrl, \PHP_URL_HOST);

        return \is_string($host) && \in_array(strtolower($host), self::HOSTS, true);
    }

    private function poster(string $pageHtml): ?string
    {
        if (preg_match(self::POSTER_PATTERN, $pageHtml, $m) !== 1) {
            return null;
        }

        return preg_match('#^https://#i', $m[1]) === 1 ? $m[1] : null;
    }

    private function widestVideo(string $pageHtml): ?string
    {
        preg_match_all(self::VIDEO_PATTERN, $pageHtml, $matches);
        $durable = array_values(array_filter($matches[0], fn (string $u): bool => $this->durable->accepts($u)));
        if ($durable === []) {
            return null;
        }

        foreach (self::RENDITION_ORDER as $rendition) {
            foreach ($durable as $url) {
                if (str_contains($url, $rendition)) {
                    return $url;
                }
            }
        }

        return $durable[0];
    }
}
