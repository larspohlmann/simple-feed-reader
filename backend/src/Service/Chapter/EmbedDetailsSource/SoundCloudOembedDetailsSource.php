<?php

declare(strict_types=1);

namespace App\Service\Chapter\EmbedDetailsSource;

use App\Service\Chapter\GuardedDescriptionRequest;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Chapter\Model\EmbedDescriptionModel;
use App\Service\Fetch\Model\ApiRequestModel;

/** Reads a track's title, description and artwork from SoundCloud's oEmbed endpoint, which needs no key and tells no length. */
final readonly class SoundCloudOembedDetailsSource implements EmbedDetailsSourceInterface
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

    public function detailsOf(EmbedAddressModel $embed): EmbedDescriptionModel
    {
        $oembed = $this->request->answerOf(ApiRequestModel::get(
            self::OEMBED . http_build_query(['format' => 'json', 'url' => self::TRACK . $embed->id]),
        ), $embed);
        $title = $oembed['title'] ?? null;
        $description = $oembed['description'] ?? null;
        $artwork = $oembed['thumbnail_url'] ?? null;

        return new EmbedDescriptionModel(
            \is_string($title) && $title !== '' ? $title : null,
            null,
            \is_string($description) ? $description : '',
            \is_string($artwork) && str_starts_with($artwork, 'https://') ? $artwork : null,
        );
    }
}
