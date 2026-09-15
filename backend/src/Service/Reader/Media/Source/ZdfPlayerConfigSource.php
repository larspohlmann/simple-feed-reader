<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Sibling\NearbyPoster;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * ZDF names each inline clip only in its player config — `"content":"<id>"`
 * beside a `"startImage"` — and plays it from `https://<host>/api/video/<id>.m3u8`,
 * which redirects to the durable stream. The schema.org VideoObject other
 * sources seed from is present only for a hero video, so a text-led article
 * ships none and its clips would reach the body as dead poster images. This
 * source seeds from the config instead, so every clip a page declares becomes a
 * player whether or not the page also carries the VideoObject (#1055).
 *
 * On a hero-video page a VideoObject names one of these streams too; the scanner
 * merges that one by URL, and SiblingMediaExtender drops the overlapping
 * re-derivations before they reach the network.
 */
#[AsTaggedItem(priority: 90)]
final readonly class ZdfPlayerConfigSource implements MediaCandidateSourceInterface
{
    private const string ZDF_HOST = '/(^|\.)zdf(heute)?\.de$/i';
    private const string PLAYER_CONFIG =
        '#\\\\?"?content\\\\?"?\s*[:=]\s*\\\\?"?([A-Za-z0-9-]{6,})\\\\?"?\s*,\s*\\\\?"?startImage#i';
    private const string STREAM_URL = 'https://%s/api/video/%s.m3u8';

    public function __construct(private MediaUrlKind $mediaUrlKind)
    {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        $host = parse_url($pageUrl, \PHP_URL_HOST);
        if (!\is_string($host) || preg_match(self::ZDF_HOST, $host) !== 1) {
            return [];
        }

        preg_match_all(self::PLAYER_CONFIG, $pageHtml, $matches, \PREG_OFFSET_CAPTURE);
        $found = [];
        foreach ($matches[1] as [$id, $position]) {
            $candidate = $this->candidateFor($host, $id, $position, $pageHtml);
            if ($candidate !== null) {
                $found[$candidate->url] ??= $candidate;
            }
        }

        return array_values($found);
    }

    private function candidateFor(string $host, string $id, int $position, string $pageHtml): ?MediaCandidate
    {
        $resolved = $this->mediaUrlKind->resolve(sprintf(self::STREAM_URL, $host, $id));
        if ($resolved?->kind !== MediaKind::Stream) {
            return null;
        }
        $poster = NearbyPoster::after($pageHtml, $position);
        if ($poster === null) {
            return null;
        }

        return new MediaCandidate($resolved->kind, $resolved->url, $poster);
    }
}
