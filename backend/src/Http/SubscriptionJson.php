<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Service\Subscription\Model\SubscriptionTalliesModel;
use App\Service\Text\Support\PlainText;
use App\Service\Url\Support\FeedWebsite;

final class SubscriptionJson
{
    /**
     * The sidebar bootstrap returns every subscription in one payload, so a
     * feed that ships a whole About page as its <description> would weigh the
     * whole reader down for a block that shows a few lines.
     */
    private const int DESCRIPTION_MAX = 1000;

    /**
     * @param list<Subscription> $subscriptions
     *
     * @return array<string, mixed>
     */
    public static function list(array $subscriptions, SubscriptionTalliesModel $tallies): array
    {
        return [
            'subscriptions' => array_map(
                static fn (Subscription $subscription): array => self::one(
                    $subscription,
                    $tallies->unreadCounts[$subscription->requireId()] ?? 0,
                    $tallies->entryCounts[$subscription->requireId()] ?? 0,
                ),
                $subscriptions,
            ),
            ...SubscriptionCountsJson::surfaceTotals($tallies),
        ];
    }

    /**
     * An embedded tag's `position` is this feed's order within that tag, not the tag's sidebar order; the top-level
     * `position` is the feed's order in the untagged "Feeds" list.
     *
     * @return array{
     *   id: int|null, feedId: int|null, title: string, customTitle: string|null, feedUrl: string,
     *   siteUrl: string|null, faviconUrl: string|null, description: string|null, imageUrl: string|null,
     *   status: string, sourceFormat: string,
     *   createdAt: string, lastFetchedAt: string|null,
     *   lastSuccessfulFetchAt: string|null, lastNewContentAt: string|null,
     *   nextFetchAt: string|null,
     *   consecutiveFailures: int, lastErrorMessage: string|null,
     *   position: int,
     *   tags: list<array{id: int|null, name: string, color: string|null, icon: string|null, position: int}>,
     *   unreadCount: int, entryCount: int, includeInAllItems: bool, includeInForYou: bool
     * }
     */
    public static function one(Subscription $subscription, int $unreadCount = 0, int $entryCount = 0): array
    {
        $feed = $subscription->getFeed();
        $title = $subscription->getCustomTitle() ?? $feed->getTitle() ?? $feed->getUrl();

        $tags = [];
        foreach ($subscription->getSubscriptionTags() as $subscriptionTag) {
            $tags[] = [...TagJson::one($subscriptionTag->getTag()), 'position' => $subscriptionTag->getPosition()];
        }

        return [
            'id' => $subscription->getId(),
            'feedId' => $feed->getId(),
            'title' => $title,
            'customTitle' => $subscription->getCustomTitle(),
            'feedUrl' => $feed->getUrl(),
            'siteUrl' => self::siteUrl($feed),
            'faviconUrl' => $feed->getFaviconUrl(),
            'description' => self::description($feed),
            'imageUrl' => $feed->getImageUrl(),
            'status' => $feed->getStatus()->value,
            // 'xml' or 'scraped' — lets the UI mark synthesized feeds, whose
            // entries are teasers rather than the feed author's own content.
            'sourceFormat' => $feed->getSourceFormat(),
            'createdAt' => $subscription->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastFetchedAt' => $feed->getLastFetchedAt()?->format(\DateTimeInterface::ATOM),
            'lastSuccessfulFetchAt' => $feed->getLastSuccessfulFetchAt()?->format(\DateTimeInterface::ATOM),
            'lastNewContentAt' => $feed->getLastNewEntryAt()?->format(\DateTimeInterface::ATOM),
            'nextFetchAt' => $feed->getNextFetchAt()?->format(\DateTimeInterface::ATOM),
            'consecutiveFailures' => $feed->getConsecutiveFailures(),
            'lastErrorMessage' => $feed->getLastErrorMessage(),
            'position' => $subscription->getPosition(),
            'tags' => $tags,
            'unreadCount' => $unreadCount,
            'entryCount' => $entryCount,
            'includeInAllItems' => $subscription->isIncludeInAllItems(),
            'includeInForYou' => $subscription->isIncludeInForYou(),
        ];
    }

    private static function siteUrl(Feed $feed): ?string
    {
        return FeedWebsite::of($feed->getUrl(), $feed->getSiteUrl());
    }

    /**
     * Plain text, so the SPA never makes a sanitiser decision. A reduction with no letter or digit is dropped (a feed
     * describing itself as ">"); doing it here, not at ingest, also covers rows already stored.
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
