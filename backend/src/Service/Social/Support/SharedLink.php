<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\Entry;
use App\Service\Social\Model\SharedLinkModel;
use App\Service\Text\Support\EntryExcerpt;
use App\Service\Text\Support\WordBoundary;

final class SharedLink
{
    private const int MAX_DESCRIPTION_LENGTH = 300;

    /** @param string $url the entry's address, already normalised */
    public static function of(Entry $entry, string $url): SharedLinkModel
    {
        $excerpt = EntryExcerpt::of($entry->getSummary(), $entry->getContentHtml());
        $description = WordBoundary::cut($excerpt, self::MAX_DESCRIPTION_LENGTH);

        return new SharedLinkModel(
            $url,
            $entry->getTitle(),
            '' === $description ? null : $description,
            $entry->getImageUrl(),
        );
    }

    private function __construct()
    {
    }
}
