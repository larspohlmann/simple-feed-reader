<?php

declare(strict_types=1);

namespace App\Service\Content\Chapter\EmbedDetailsSource;

use App\Service\Content\Chapter\GuardedDescriptionRequest;
use App\Service\Content\Chapter\Model\ChapteredEmbed;
use App\Service\Content\Chapter\Model\EmbedAddressModel;
use App\Service\Content\Chapter\Model\EmbedDescriptionModel;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Foundation\Url\Support\HttpsImageUrl;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** Reads a track's title, description and artwork from SoundCloud's oEmbed endpoint, which needs no key and tells no length. */
#[AsTaggedItem(index: ChapteredEmbed::SoundCloud->value)]
final readonly class SoundCloudOembedDetailsSource implements EmbedDetailsSourceInterface
{
    private const string OEMBED = 'https://soundcloud.com/oembed?';
    private const string TRACK = 'https://api.soundcloud.com/tracks/';

    public function __construct(private GuardedDescriptionRequest $request)
    {
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
            HttpsImageUrl::orNull(\is_string($artwork) ? $artwork : null),
        );
    }
}
