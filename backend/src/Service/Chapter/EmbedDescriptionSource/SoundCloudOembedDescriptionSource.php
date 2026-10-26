<?php

declare(strict_types=1);

namespace App\Service\Chapter\EmbedDescriptionSource;

use App\Service\Chapter\GuardedDescriptionRequest;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Fetch\Model\ApiRequestModel;

/** Reads a track's description from SoundCloud's oEmbed endpoint, which needs no key. */
final readonly class SoundCloudOembedDescriptionSource implements EmbedDescriptionSourceInterface
{
    private const string OEMBED = 'https://soundcloud.com/oembed?';
    private const string TRACK = 'https://api.soundcloud.com/tracks/';

    public function __construct(private GuardedDescriptionRequest $request)
    {
    }

    public static function provider(): string
    {
        return ChapteredEmbed::SoundCloud->value;
    }

    public function descriptionOf(EmbedAddressModel $embed): string
    {
        $oembed = $this->request->answerOf(ApiRequestModel::get(
            self::OEMBED . http_build_query(['format' => 'json', 'url' => self::TRACK . $embed->id]),
        ), $embed);
        $description = $oembed['description'] ?? null;

        return \is_string($description) ? $description : '';
    }
}
