<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Subscription;

final class SubscriptionJson
{
    /**
     * The embedded tag's `position` is this feed's order WITHIN that tag (the
     * join position) — not the tag's own sidebar order, which the tag-list
     * endpoint carries. `position` at the top level is the feed's order in the
     * untagged "Feeds" list.
     *
     * @return array{
     *   id: int|null, feedId: int|null, title: string, customTitle: string|null, feedUrl: string,
     *   siteUrl: string|null, status: string, sourceFormat: string, createdAt: string, position: int,
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
            'siteUrl' => $feed->getSiteUrl(),
            'status' => $feed->getStatus()->value,
            // 'xml' or 'scraped' — lets the UI mark synthesized feeds, whose
            // entries are teasers rather than the feed author's own content.
            'sourceFormat' => $feed->getSourceFormat(),
            'createdAt' => $sub->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'position' => $sub->getPosition(),
            'tags' => $tags,
            'unreadCount' => $unreadCount,
        ];
    }
}
