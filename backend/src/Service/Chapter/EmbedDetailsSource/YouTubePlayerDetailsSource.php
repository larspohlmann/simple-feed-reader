<?php

declare(strict_types=1);

namespace App\Service\Chapter\EmbedDetailsSource;

use App\Service\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Chapter\GuardedDescriptionRequest;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Chapter\Model\EmbedDescriptionModel;
use App\Service\Fetch\Model\ApiRequestModel;

/** Reads a video's title, length and description from YouTube's player endpoint, which needs no key. */
final readonly class YouTubePlayerDetailsSource implements EmbedDetailsSourceInterface
{
    private const string PLAYER = 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false';
    private const string CLIENT_VERSION = '2.20241001.00.00';

    public function __construct(private GuardedDescriptionRequest $request)
    {
    }

    public static function provider(): string
    {
        return ChapteredEmbed::YouTube->value;
    }

    public function detailsOf(EmbedAddressModel $embed): EmbedDescriptionModel
    {
        $player = $this->request->answerOf(ApiRequestModel::postJson(self::PLAYER, [
            'videoId' => $embed->id,
            'context' => ['client' => ['clientName' => 'WEB', 'clientVersion' => self::CLIENT_VERSION, 'hl' => 'en']],
        ]), $embed);
        $video = $player['videoDetails'] ?? null;
        if (!\is_array($video)) {
            throw EmbedDescriptionUnreachableException::withoutTheVideo($embed);
        }
        $title = $video['title'] ?? null;
        $description = $video['shortDescription'] ?? null;

        return new EmbedDescriptionModel(
            \is_string($title) && $title !== '' ? $title : null,
            self::secondsIn($video['lengthSeconds'] ?? null),
            \is_string($description) ? $description : '',
        );
    }

    private static function secondsIn(mixed $lengthSeconds): ?int
    {
        $digits = \is_int($lengthSeconds) ? (string) $lengthSeconds : $lengthSeconds;
        if (!\is_string($digits) || !ctype_digit($digits)) {
            return null;
        }

        return (int) $digits > 0 ? (int) $digits : null;
    }
}
