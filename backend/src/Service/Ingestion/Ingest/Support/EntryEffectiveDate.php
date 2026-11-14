<?php

declare(strict_types=1);

namespace App\Service\Ingest\Support;

use App\Service\Ingest\Pass\FeedIngestContext;

/**
 * An entry's place in the list: its published date if that predates the feed's previous SUCCESSFUL fetch (a failed
 * attempt proves nothing about what was served) or there is none, else the fetch instant. A missing or future date
 * takes the fetch instant: nothing outranks it.
 */
final class EntryEffectiveDate
{
    public static function for(?\DateTimeImmutable $publishedAt, FeedIngestContext $context): \DateTimeImmutable
    {
        if (null === $publishedAt || $publishedAt > $context->fetchedAt) {
            return $context->fetchedAt;
        }

        $previousFetchAt = $context->previousFetchAt;
        if (null === $previousFetchAt) {
            return $publishedAt;
        }

        return $publishedAt < $previousFetchAt ? $publishedAt : $context->fetchedAt;
    }

    private function __construct()
    {
    }
}
