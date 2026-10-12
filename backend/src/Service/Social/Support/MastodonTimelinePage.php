<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\LinkCardContent;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Fetch\Support\HostKey;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\LinkCard\Support\StorableLinkCard;
use App\Service\Parser\Model\ParsedEntryMediaModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedMediaBundleModel;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Parser\Support\DateParser;
use App\Service\Parser\Support\EntryTitle;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Text\Support\HtmlEscape;
use App\Service\Url\Support\AbsoluteHttpUrl;
use App\Service\Url\Support\HttpsImageUrl;

/** A home or list timeline answer as entries; replies are already filtered by the instance. */
final class MastodonTimelinePage
{
    private const array VIDEO_TYPES = ['video', 'gifv'];

    /** @param list<JsonNodeModel> $statuses */
    public static function of(array $statuses): TimelinePageModel
    {
        $entries = [];
        $cards = [];
        foreach ($statuses as $status) {
            $entry = self::entryOf($status);
            if (null === $entry) {
                continue;
            }
            $entries[] = $entry;
            $card = self::cardOf(self::originalOf($status)->node('card'));
            if (null !== $card) {
                $cards[$entry->guid] = $card;
            }
        }

        return TimelinePageModel::of($entries, KnownSharesModel::ofLinkCards($cards), self::newestIdOf($statuses));
    }

    private static function entryOf(JsonNodeModel $status): ?ParsedEntryModel
    {
        $post = self::originalOf($status);
        $uri = $post->string('uri');
        $url = AbsoluteHttpUrl::orNull($post->string('url')) ?? AbsoluteHttpUrl::orNull($uri);
        if (null === $uri || null === $url) {
            return null;
        }
        $body = self::bodyOf($post);
        $account = $post->node('account');

        return new ParsedEntryModel(
            guid: $uri,
            url: $url,
            headline: EntryTitle::of(null, $body),
            author: self::nameOf($account),
            summary: null,
            contentHtml: self::withBoostAttribution($body, $status),
            publishedAt: DateParser::parse($status->string('created_at')),
            media: self::mediaOf($post->nodes('media_attachments')),
            authorUrl: self::profileOf($account),
            authorAvatarUrl: AbsoluteHttpUrl::orNull($account->string('avatar')),
        );
    }

    private static function originalOf(JsonNodeModel $status): JsonNodeModel
    {
        return self::isBoost($status) ? $status->node('reblog') : $status;
    }

    private static function isBoost(JsonNodeModel $status): bool
    {
        return null !== $status->node('reblog')->string('uri');
    }

    private static function bodyOf(JsonNodeModel $post): ?string
    {
        $warning = $post->string('spoiler_text');
        $html = (null === $warning ? '' : sprintf('<p><strong>CW: %s</strong></p>', HtmlEscape::text($warning)))
            . ($post->string('content') ?? '');

        return '' === $html ? null : $html;
    }

    private static function withBoostAttribution(?string $body, JsonNodeModel $status): ?string
    {
        if (!self::isBoost($status)) {
            return $body;
        }
        $booster = $status->node('account');

        return ($body ?? '') . ShareAttribution::line('Boosted by', self::nameOf($booster), self::profileOf($booster));
    }

    private static function nameOf(JsonNodeModel $account): ?string
    {
        return $account->string('display_name') ?? $account->string('acct');
    }

    private static function profileOf(JsonNodeModel $account): ?string
    {
        return AbsoluteHttpUrl::orNull($account->string('url'));
    }

    /** @param list<JsonNodeModel> $attachments */
    private static function mediaOf(array $attachments): ParsedEntryMediaModel
    {
        $media = [];
        foreach ($attachments as $attachment) {
            $medium = self::mediumOf($attachment);
            if (null !== $medium) {
                $media[] = $medium;
            }
        }
        if ([] === $media) {
            return new ParsedEntryMediaModel();
        }

        return new ParsedEntryMediaModel(self::leadImageOf($media), new ParsedMediaBundleModel($media));
    }

    private static function mediumOf(JsonNodeModel $attachment): ?ParsedMediumModel
    {
        $url = HttpsImageUrl::orNull($attachment->string('url'));
        $size = $attachment->node('meta')->node('original');
        $type = $attachment->string('type');

        return match (true) {
            null === $url => null,
            'image' === $type => new ParsedMediumModel(
                $url,
                VisualMediaKind::Image,
                $size->int('width'),
                $size->int('height'),
            ),
            \in_array($type, self::VIDEO_TYPES, true) => new ParsedMediumModel(
                $url,
                VisualMediaKind::Video,
                $size->int('width'),
                $size->int('height'),
                HttpsImageUrl::orNull($attachment->string('preview_url')),
            ),
            default => null,
        };
    }

    /** @param list<ParsedMediumModel> $media */
    private static function leadImageOf(array $media): ?DeclaredImageModel
    {
        foreach ($media as $medium) {
            if (VisualMediaKind::Image === $medium->kind) {
                return new DeclaredImageModel($medium->url, $medium->width, $medium->height);
            }
        }

        return null;
    }

    private static function cardOf(JsonNodeModel $card): ?LinkCardContent
    {
        $url = $card->string('url');
        $title = $card->string('title');
        if (null === $url || null === $title) {
            return null;
        }

        return StorableLinkCard::of(new LinkCardContent(
            $url,
            $title,
            $card->string('description'),
            $card->string('image'),
            $card->string('provider_name') ?? HostKey::forUrl($url),
        ));
    }

    /** @param list<JsonNodeModel> $statuses */
    private static function newestIdOf(array $statuses): ?string
    {
        $newest = null;
        foreach ($statuses as $status) {
            $id = $status->string('id');
            if (null !== $id && (null === $newest || self::isHigherId($id, $newest))) {
                $newest = $id;
            }
        }

        return $newest;
    }

    /** Ids are digit strings that may outgrow an int: compare by length, then digit by digit. */
    private static function isHigherId(string $id, string $than): bool
    {
        return (\strlen($id) <=> \strlen($than) ?: strcmp($id, $than)) > 0;
    }

    private function __construct()
    {
    }
}
