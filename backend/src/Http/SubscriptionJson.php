<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Subscription;

final class SubscriptionJson
{
    /**
     * @return array{
     *   id: int|null, title: string, customTitle: string|null, feedUrl: string,
     *   siteUrl: string|null, status: string, createdAt: string,
     *   tags: list<array{id: int|null, name: string, color: string|null, icon: string|null}>,
     *   unreadCount: int
     * }
     */
    public static function one(Subscription $sub, int $unreadCount = 0): array
    {
        $feed = $sub->getFeed();
        $title = $sub->getCustomTitle() ?? $feed->getTitle() ?? $feed->getUrl();

        $tags = [];
        foreach ($sub->getTags() as $tag) {
            $tags[] = TagJson::one($tag);
        }

        return [
            'id' => $sub->getId(),
            'title' => $title,
            'customTitle' => $sub->getCustomTitle(),
            'feedUrl' => $feed->getUrl(),
            'siteUrl' => $feed->getSiteUrl(),
            'status' => $feed->getStatus()->value,
            'createdAt' => $sub->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'tags' => $tags,
            'unreadCount' => $unreadCount,
        ];
    }
}
