<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\EmbedProvider;

use App\Service\Reader\Media\Model\EmbedFrameModel;
use App\Service\Reader\Media\Model\EmbedKind;
use App\Service\Reader\Media\Model\EmbedShape;
use App\Service\Reader\Media\Model\EmbedTargetModel;
use App\Service\Url\Model\YouTubeVideoModel;
use App\Service\Url\Support\YouTubeUrl;

/**
 * YouTube in every spelling a publisher uses, reduced to one nocookie embed.
 * The video id is the whole payload, so the query goes: `?si=` is a share
 * token, and `rel`/`autoplay`/`showinfo` are player preferences we override.
 */
final readonly class YouTubeEmbedProvider implements EmbedProviderInterface
{
    private const string LABEL = 'Watch on YouTube';

    public function target(string $url): ?EmbedTargetModel
    {
        $video = YouTubeUrl::video($url);
        if ($video === null) {
            return null;
        }

        return new EmbedTargetModel($video->playerUrl(), $video->posterUrl(), self::LABEL);
    }

    public function frames(): array
    {
        $video = '^https://www\.youtube-nocookie\.com/embed/' . YouTubeUrl::VIDEO_ID_PATTERN;
        $short = $video . YouTubeVideoModel::SHORTS_FRAGMENT;

        return [
            new EmbedFrameModel($video . '$', EmbedKind::Video, EmbedShape::Landscape),
            new EmbedFrameModel($short . '$', EmbedKind::Video, EmbedShape::Portrait),
        ];
    }

    public function sourceHosts(): array
    {
        return YouTubeUrl::HOSTS;
    }
}
