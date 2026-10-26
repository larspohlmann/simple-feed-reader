<?php

declare(strict_types=1);

namespace App\Service\Chapter\EmbedDescriptionSource;

use App\Service\Chapter\GuardedDescriptionRequest;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Fetch\Model\ApiRequestModel;

/** Reads a video's description from YouTube's player endpoint, which needs no key. */
final readonly class YouTubePlayerDescriptionSource implements EmbedDescriptionSourceInterface
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

    public function descriptionOf(EmbedAddressModel $embed): string
    {
        $player = $this->request->answerOf(ApiRequestModel::postJson(self::PLAYER, [
            'videoId' => $embed->id,
            'context' => ['client' => ['clientName' => 'WEB', 'clientVersion' => self::CLIENT_VERSION, 'hl' => 'en']],
        ]), $embed);
        $details = $player['videoDetails'] ?? null;
        $description = \is_array($details) ? ($details['shortDescription'] ?? null) : null;

        return \is_string($description) ? $description : '';
    }
}
