<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Model\ParsedEntryMediaModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedMediaBundleModel;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Parser\Support\DateParser;
use App\Service\Parser\Support\EntryTitle;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\Model\TimelineSelfReplyModel;
use App\Service\Text\Support\HtmlEscape;
use App\Service\Url\Support\AbsoluteHttpUrl;

/** A home or list timeline answer as entries; a thread of the author's own replies on the page is handed on. */
final class MastodonTimelinePage
{
    /** @param list<JsonNodeModel> $statuses */
    public static function of(array $statuses): TimelinePageModel
    {
        $byId = self::ownStatusesById($statuses);
        $entries = [];
        $selfReplies = [];
        $postsByGuid = [];
        foreach ($statuses as $status) {
            $entry = self::entryOf($status);
            if (null === $entry) {
                continue;
            }
            $postsByGuid[$entry->guid] = self::originalOf($status);
            $selfReply = self::selfReplyOf($status, $entry, $byId);
            if (null === $selfReply) {
                $entries[] = $entry;
                continue;
            }
            $selfReplies[] = $selfReply;
        }

        return TimelinePageModel::of($entries, self::knownSharesOf($postsByGuid), self::newestIdOf($statuses))
            ->withSelfReplies($selfReplies);
    }

    /**
     * @param list<JsonNodeModel> $statuses
     *
     * @return array<string, JsonNodeModel>
     */
    private static function ownStatusesById(array $statuses): array
    {
        $byId = [];
        foreach ($statuses as $status) {
            $id = $status->string('id');
            if (null !== $id && !self::isBoost($status)) {
                $byId[$id] = $status;
            }
        }

        return $byId;
    }

    /** @param array<string, JsonNodeModel> $byId */
    private static function selfReplyOf(
        JsonNodeModel $status,
        ParsedEntryModel $entry,
        array $byId,
    ): ?TimelineSelfReplyModel {
        $parent = self::isBoost($status) ? null : self::ownParentOf($status, $byId);
        if (null === $parent) {
            return null;
        }
        $root = self::rootOf($parent, $byId);
        $rootUri = $root->string('uri');
        $parentUrl = self::urlOf($parent);
        if (null === $rootUri || null === $parentUrl || null !== $root->string('in_reply_to_id')) {
            return null;
        }

        return new TimelineSelfReplyModel($rootUri, $parentUrl, $entry, $status);
    }

    /** @param array<string, JsonNodeModel> $byId */
    private static function rootOf(JsonNodeModel $status, array $byId): JsonNodeModel
    {
        $parent = self::ownParentOf($status, $byId);

        return null === $parent ? $status : self::rootOf($parent, $byId);
    }

    /**
     * The post a status answers when its author answers themself and the post is on the page.
     *
     * @param array<string, JsonNodeModel> $byId
     */
    private static function ownParentOf(JsonNodeModel $status, array $byId): ?JsonNodeModel
    {
        $accountId = $status->node('account')->string('id');
        if (null === $accountId || $accountId !== $status->string('in_reply_to_account_id')) {
            return null;
        }

        return $byId[$status->string('in_reply_to_id') ?? ''] ?? null;
    }

    private static function urlOf(JsonNodeModel $post): ?string
    {
        return AbsoluteHttpUrl::orNull($post->string('url')) ?? AbsoluteHttpUrl::orNull($post->string('uri'));
    }

    /** @param array<string, JsonNodeModel> $postsByGuid */
    private static function knownSharesOf(array $postsByGuid): KnownSharesModel
    {
        $cards = array_filter(array_map(
            static fn (JsonNodeModel $post): ?LinkCardContent => MastodonCard::of($post->node('card')),
            $postsByGuid,
        ));
        $quotes = array_filter(array_map(
            static fn (JsonNodeModel $post): ?QuotedPostContent => MastodonQuotedStatus::ofQuote($post->node('quote')),
            $postsByGuid,
        ));

        return KnownSharesModel::ofMastodonShares($cards, $quotes);
    }

    private static function entryOf(JsonNodeModel $status): ?ParsedEntryModel
    {
        $post = self::originalOf($status);
        $uri = $post->string('uri');
        $url = self::urlOf($post);
        if (null === $uri || null === $url) {
            return null;
        }
        $body = self::bodyOf($post);
        $account = $post->node('account');

        return new ParsedEntryModel(
            guid: $uri,
            url: $url,
            headline: EntryTitle::of(null, $body),
            author: MastodonAuthor::name($account),
            summary: null,
            contentHtml: self::withBoostAttribution($body, $status),
            publishedAt: DateParser::parse($status->string('created_at')),
            media: self::mediaOf($post->nodes('media_attachments')),
            authorUrl: MastodonAuthor::profile($account),
            authorAvatarUrl: MastodonAuthor::avatar($account),
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

        return ($body ?? '') . ShareAttribution::line(
            'Boosted by',
            MastodonAuthor::name($booster),
            MastodonAuthor::profile($booster),
        );
    }

    /** @param list<JsonNodeModel> $attachments */
    private static function mediaOf(array $attachments): ParsedEntryMediaModel
    {
        $media = MastodonAttachments::of($attachments);
        if ([] === $media) {
            return new ParsedEntryMediaModel();
        }

        return new ParsedEntryMediaModel(self::leadImageOf($media), new ParsedMediaBundleModel($media));
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
