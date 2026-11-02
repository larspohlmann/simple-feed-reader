<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Support;

use App\Entity\LinkCardContent;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Support\HostKey;
use App\Service\LinkCard\Support\StorableLinkCard;
use App\Service\Url\Support\AbsoluteHttpUrl;

/** The link card an external embed's view shows; none without a web page's URL and a title. */
final class BlueskyExternalCard
{
    private const string EXTERNAL = 'app.bsky.embed.external#view';
    private const string RECORD_WITH_MEDIA = 'app.bsky.embed.recordWithMedia#view';

    public static function ofEmbed(JsonNodeModel $embed): ?LinkCardContent
    {
        return match ($embed->type()) {
            self::EXTERNAL => self::ofNonGif($embed->node('external')),
            self::RECORD_WITH_MEDIA => self::ofEmbed($embed->node('media')),
            default => null,
        };
    }

    public static function of(JsonNodeModel $external): ?LinkCardContent
    {
        $url = AbsoluteHttpUrl::orNull($external->string('uri'));
        $title = $external->string('title');
        if ($url === null || $title === null) {
            return null;
        }

        return StorableLinkCard::of(new LinkCardContent(
            $url,
            $title,
            $external->string('description'),
            $external->string('thumb'),
            HostKey::forUrl($url),
        ));
    }

    private static function ofNonGif(JsonNodeModel $external): ?LinkCardContent
    {
        return BlueskyEmbedMedia::gif($external) === null ? self::of($external) : null;
    }

    private function __construct()
    {
    }
}
