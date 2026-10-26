<?php

declare(strict_types=1);

namespace App\Service\Chapter\EmbedDetailsSource;

use App\Service\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Chapter\GuardedDescriptionRequest;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Chapter\Model\EmbedDescriptionModel;
use App\Service\Fetch\Exception\DirectRouteUnavailableException;
use App\Service\Fetch\Model\ApiRequestModel;
use Psr\Log\LoggerInterface;

/** Reads a video's title, length and description from YouTube's player endpoint, which needs no key. */
final readonly class YouTubePlayerDetailsSource implements EmbedDetailsSourceInterface
{
    private const string PLAYER = 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false';
    private const string CLIENT_VERSION = '2.20241001.00.00';

    public function __construct(private GuardedDescriptionRequest $request, private LoggerInterface $logger)
    {
    }

    public static function provider(): string
    {
        return ChapteredEmbed::YouTube->value;
    }

    public function detailsOf(EmbedAddressModel $embed): EmbedDescriptionModel
    {
        $video = $this->videoOf($embed);
        $title = $video['title'] ?? null;
        $description = $video['shortDescription'] ?? null;

        return new EmbedDescriptionModel(
            \is_string($title) && $title !== '' ? $title : null,
            self::secondsIn($video['lengthSeconds'] ?? null),
            \is_string($description) ? $description : '',
        );
    }

    /** @return array<mixed> */
    private function videoOf(EmbedAddressModel $embed): array
    {
        $request = self::playerRequestFor($embed);
        $video = self::videoDetailsIn($this->request->answerOf($request, $embed))
            ?? self::videoDetailsIn($this->askedDirectly($request, $embed));
        if ($video === null) {
            throw EmbedDescriptionUnreachableException::withoutTheVideo($embed);
        }

        return $video;
    }

    /** @return array<mixed> */
    private function askedDirectly(ApiRequestModel $request, EmbedAddressModel $embed): array
    {
        try {
            $player = $this->request->directAnswerOf($request, $embed);
        } catch (DirectRouteUnavailableException) {
            throw EmbedDescriptionUnreachableException::withoutTheVideo($embed);
        }
        $this->logger->info('YouTube walled the proxied player request for {videoId}; asked again directly.', [
            'videoId' => $embed->id,
            'walledAgain' => self::videoDetailsIn($player) === null,
        ]);

        return $player;
    }

    private static function playerRequestFor(EmbedAddressModel $embed): ApiRequestModel
    {
        return ApiRequestModel::postJson(self::PLAYER, [
            'videoId' => $embed->id,
            'context' => ['client' => ['clientName' => 'WEB', 'clientVersion' => self::CLIENT_VERSION, 'hl' => 'en']],
        ]);
    }

    /**
     * @param array<mixed> $player
     * @return array<mixed>|null
     */
    private static function videoDetailsIn(array $player): ?array
    {
        $video = $player['videoDetails'] ?? null;

        return \is_array($video) ? $video : null;
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
