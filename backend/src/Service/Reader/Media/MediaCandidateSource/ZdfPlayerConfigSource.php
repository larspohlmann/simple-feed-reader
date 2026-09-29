<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\MediaCandidateSource;

use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaKind;
use App\Service\Reader\Media\Model\RawPageModel;
use App\Service\Reader\Media\Sibling\NearbyPoster;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * ZDF names an inline clip only in its player config (`"content":"<id>"` beside a `"startImage"`), played from
 * `https://<host>/api/video/<id>.m3u8`. Only a hero video has a VideoObject, so every clip is seeded from the config;
 * the scanner merges the hero by URL.
 */
#[AsTaggedItem(priority: 90)]
final readonly class ZdfPlayerConfigSource implements MediaCandidateSourceInterface
{
    private const string ZDF_HOST = '/(^|\.)zdf(heute)?\.de$/i';
    private const string PLAYER_CONFIG =
        '#\\\\?"?content\\\\?"?\s*[:=]\s*\\\\?"?([A-Za-z0-9-]{6,})\\\\?"?\s*,\s*\\\\?"?startImage#i';
    private const string STREAM_URL = 'https://%s/api/video/%s.m3u8';

    public function __construct(private MediaUrlKind $mediaUrlKind, private NearbyPoster $nearbyPoster)
    {
    }

    public function find(RawPageModel $page): array
    {
        $host = parse_url($page->url, \PHP_URL_HOST);
        if (!\is_string($host) || preg_match(self::ZDF_HOST, $host) !== 1) {
            return [];
        }

        preg_match_all(self::PLAYER_CONFIG, $page->html, $matches, \PREG_OFFSET_CAPTURE);
        $found = [];
        foreach ($matches[1] as [$id, $position]) {
            $candidate = $this->candidateFor($host, $id, $position, $page->html);
            if ($candidate !== null) {
                $found[$candidate->url] ??= $candidate;
            }
        }

        return array_values($found);
    }

    private function candidateFor(string $host, string $id, int $position, string $pageHtml): ?MediaCandidateModel
    {
        $resolved = $this->mediaUrlKind->resolve(sprintf(self::STREAM_URL, $host, $id));
        if ($resolved?->kind !== MediaKind::Stream) {
            return null;
        }
        $poster = $this->nearbyPoster->after($pageHtml, $position);
        if ($poster === null) {
            return null;
        }

        return new MediaCandidateModel($resolved->kind, $resolved->url, $poster);
    }
}
