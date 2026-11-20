<?php

declare(strict_types=1);

namespace App\Service\Social\BlueskyPost\Support;

use App\Entity\LinkCardContent;
use App\Service\Content\LinkCard\Support\StorableLinkCard;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Outbound\Fetch\Support\HostKey;

/** The link card an external embed's view shows; none without a web page's URL and a title. */
final class BlueskyExternalCard
{
    private const string EXTERNAL = 'app.bsky.embed.external#view';
    private const string RECORD_WITH_MEDIA = 'app.bsky.embed.recordWithMedia#view';

    public static function fromEmbed(JsonNodeModel $embed): ?LinkCardContent
    {
        return match ($embed->type()) {
            self::EXTERNAL => self::fromNonGifExternal($embed->node('external')),
            self::RECORD_WITH_MEDIA => self::fromEmbed($embed->node('media')),
            default => null,
        };
    }

    public static function fromExternal(JsonNodeModel $external): ?LinkCardContent
    {
        $url = AbsoluteHttpUrl::orNull($external->string('uri'));
        $title = $external->string('title');
        if ($url === null || $title === null) {
            return null;
        }

        return StorableLinkCard::fromCard(new LinkCardContent(
            $url,
            $title,
            $external->string('description'),
            $external->string('thumb'),
            HostKey::forUrl($url),
        ));
    }

    private static function fromNonGifExternal(JsonNodeModel $external): ?LinkCardContent
    {
        return BlueskyEmbedMedia::gif($external) === null ? self::fromExternal($external) : null;
    }

    private function __construct()
    {
    }
}
