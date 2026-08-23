<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Service\Url\FeedWebsite;
use App\Service\Text\PlainText;

final class SubscriptionJson
{
    /**
     * The sidebar bootstrap returns every subscription in one payload, so a
     * feed that ships a whole About page as its <description> would weigh the
     * whole reader down for a block that shows a few lines.
     */
    private const int DESCRIPTION_MAX = 1000;

    /**
     * The embedded tag's `position` is this feed's order WITHIN that tag (the
     * join position) — not the tag's own sidebar order, which the tag-list
     * endpoint carries. `position` at the top level is the feed's order in the
     * untagged "Feeds" list.
     *
     * @return array{
     *   id: int|null, feedId: int|null, title: string, customTitle: string|null, feedUrl: string,
     *   siteUrl: string|null, faviconUrl: string|null, description: string|null, imageUrl: string|null,
     *   status: string, sourceFormat: string,
     *   createdAt: string, lastFetchedAt: string|null, position: int,
     *   tags: list<array{id: int|null, name: string, color: string|null, icon: string|null, position: int}>,
     *   unreadCount: int
     * }
     */
    public static function one(Subscription $sub, int $unreadCount = 0): array
    {
        $feed = $sub->getFeed();
        $title = $sub->getCustomTitle() ?? $feed->getTitle() ?? $feed->getUrl();

        $tags = [];
        foreach ($sub->getSubscriptionTags() as $subscriptionTag) {
            // Canonical tag shape, but with the JOIN position (this feed's order
            // within the tag) in place of the tag's own sidebar position.
            $tags[] = [...TagJson::one($subscriptionTag->getTag()), 'position' => $subscriptionTag->getPosition()];
        }

        return [
            'id' => $sub->getId(),
            'feedId' => $feed->getId(),
            'title' => $title,
            'customTitle' => $sub->getCustomTitle(),
            'feedUrl' => $feed->getUrl(),
            'siteUrl' => self::siteUrl($feed),
            'faviconUrl' => $feed->getFaviconUrl(),
            'description' => self::description($feed),
            'imageUrl' => $feed->getImageUrl(),
            'status' => $feed->getStatus()->value,
            // 'xml' or 'scraped' — lets the UI mark synthesized feeds, whose
            // entries are teasers rather than the feed author's own content.
            'sourceFormat' => $feed->getSourceFormat(),
            'createdAt' => $sub->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastFetchedAt' => $feed->getLastFetchedAt()?->format(\DateTimeInterface::ATOM),
            'position' => $sub->getPosition(),
            'tags' => $tags,
            'unreadCount' => $unreadCount,
        ];
    }

    /**
     * Where the feed's website is. FeedWebsite holds the rule, because it took
     * four separate feed pathologies to arrive at and deserves its own tests.
     */
    private static function siteUrl(Feed $feed): ?string
    {
        return FeedWebsite::of($feed->getUrl(), $feed->getSiteUrl());
    }

    /**
     * Feed descriptions routinely carry markup. Reducing to plain text at the
     * boundary keeps the SPA out of any sanitiser decision: what it receives
     * is text, and it renders it as text.
     *
     * A reduction that leaves no letter and no digit is not a description.
     * Deutschlandfunk's feed describes itself as a single ">", which reached
     * the reader as a stray character floating above the headlines. Dropping
     * it here rather than at ingest also repairs the rows already stored.
     */
    private static function description(Feed $feed): ?string
    {
        $text = PlainText::fromHtmlBlocks($feed->getDescription());
        if ($text === null || preg_match('/[\p{L}\p{N}]/u', $text) !== 1) {
            return null;
        }

        return mb_substr($text, 0, self::DESCRIPTION_MAX);
    }
}
