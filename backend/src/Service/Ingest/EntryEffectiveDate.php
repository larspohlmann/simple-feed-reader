<?php

declare(strict_types=1);

namespace App\Service\Ingest;

/**
 * The instant an entry takes its place in the reader's list.
 *
 * A refresh puts what it just fetched at the top — right for an article the
 * feed just started serving, wrong for one served months ago and now
 * re-read after a prune, which must not push today's articles down.
 *
 * The two are told apart by the feed's own previous SUCCESSFUL fetch: an
 * article published before that keeps its publication date and sinks where
 * it belongs. The window is per feed on purpose — a five-minute feed and a
 * six-hour feed disagree about how old "since we last looked" is.
 *
 * "Successful" matters: a failed attempt still advances lastFetchedAt
 * (FeedScheduler::recordFailure(), recordGone()) without proving what the
 * feed served. A feed erroring nine days then recovering must give its whole
 * backlog "new to us" grace, or it would sink to old dates and look empty.
 *
 * Nothing outranks the fetch instant: a feed declaring tomorrow's date must
 * not pin itself atop the list until tomorrow arrives.
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
}
