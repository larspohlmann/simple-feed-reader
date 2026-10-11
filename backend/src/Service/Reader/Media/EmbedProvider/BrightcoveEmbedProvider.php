<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\EmbedProvider;

use App\Service\Reader\Media\Model\EmbedFrameModel;
use App\Service\Reader\Media\Model\EmbedKind;
use App\Service\Reader\Media\Model\EmbedShape;
use App\Service\Reader\Media\Model\EmbedTargetModel;

/**
 * Brightcove's hosted player page, declared as a VideoObject's embedUrl. The video id lives in the query, so the
 * query is reduced to it rather than dropped; the player id is kept verbatim.
 */
final readonly class BrightcoveEmbedProvider implements EmbedProviderInterface
{
    private const string HOST = 'players.brightcove.net';
    private const string PATH_PATTERN = '#^/(\d+)/([A-Za-z0-9_-]+)/index\.html$#';
    private const string VIDEO_ID_PATTERN = '#^\d+$#';
    private const string LABEL = 'Watch the video';

    public function target(string $url): ?EmbedTargetModel
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== self::HOST) {
            return null;
        }
        if (preg_match(self::PATH_PATTERN, $parts['path'] ?? '', $path) !== 1) {
            return null;
        }
        $videoId = $this->videoId($parts['query'] ?? '');

        if ($videoId === null) {
            return null;
        }
        $embedUrl = \sprintf('https://%s/%s/%s/index.html?videoId=%s', self::HOST, $path[1], $path[2], $videoId);

        return new EmbedTargetModel($embedUrl, null, self::LABEL);
    }

    public function frames(): array
    {
        return [new EmbedFrameModel(
            '^https://' . preg_quote(self::HOST, '#') . '/\d+/[A-Za-z0-9_-]+/index\.html\?videoId=\d+$',
            EmbedKind::Video,
            EmbedShape::Landscape,
        )];
    }

    public function sourceHosts(): array
    {
        return [self::HOST];
    }

    private function videoId(string $query): ?string
    {
        parse_str($query, $queryParameters);
        $videoId = $queryParameters['videoId'] ?? null;

        return \is_string($videoId) && preg_match(self::VIDEO_ID_PATTERN, $videoId) === 1 ? $videoId : null;
    }
}
