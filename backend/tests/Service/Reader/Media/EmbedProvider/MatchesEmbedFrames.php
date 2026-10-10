<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\EmbedProvider;

use App\Service\Reader\Media\EmbedProvider\EmbedProviderInterface;
use App\Service\Reader\Media\Model\EmbedFrameModel;

trait MatchesEmbedFrames
{
    /**
     * @param list<EmbedFrameModel> $frames
     *
     * @return list<EmbedFrameModel>
     */
    private static function framesMatching(array $frames, string $url): array
    {
        return array_values(array_filter(
            $frames,
            static fn (EmbedFrameModel $frame): bool => preg_match('~' . $frame->pattern . '~', $url) === 1,
        ));
    }

    /** @param list<EmbedFrameModel> $frames */
    private static function frameMatching(array $frames, string $url): EmbedFrameModel
    {
        $matching = self::framesMatching($frames, $url);
        self::assertCount(1, $matching, $url . ' must match exactly one frame pattern.');

        return $matching[0];
    }

    private static function onlyFrame(EmbedProviderInterface $provider): EmbedFrameModel
    {
        $frames = $provider->frames();
        self::assertCount(1, $frames);

        return $frames[0];
    }
}
