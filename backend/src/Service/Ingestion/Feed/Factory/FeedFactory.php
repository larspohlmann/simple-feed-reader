<?php

declare(strict_types=1);

namespace App\Service\Feed\Factory;

use App\Entity\Feed;

/** A new shared feed row; its first fetch fills in the rest. */
final readonly class FeedFactory
{
    public function create(string $url, string $sourceFormat, ?string $title): Feed
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);
        $feed->setTitle($title);

        return $feed;
    }
}
