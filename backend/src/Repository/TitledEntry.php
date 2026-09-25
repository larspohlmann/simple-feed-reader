<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;

/** An entry with the feed name its reader sees: their customTitle, else the feed's title, else its URL. */
final readonly class TitledEntry
{
    public function __construct(
        public Entry $entry,
        public string $feedName,
    ) {
    }

    public static function of(Entry $entry, ?string $customTitle): self
    {
        $feed = $entry->getFeed();

        return new self($entry, SubscriptionDisplayTitle::from($customTitle, $feed->getTitle(), $feed->getUrl()));
    }
}
