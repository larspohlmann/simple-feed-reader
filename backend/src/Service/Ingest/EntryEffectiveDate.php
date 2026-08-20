<?php

declare(strict_types=1);

namespace App\Service\Ingest;

/**
 * The instant an entry takes its place in the reader's list.
 *
 * A refresh puts what it just fetched at the top, which is what the reader
 * wants for an article the feed has only now started serving. It is the wrong
 * answer for an article the feed has served for months — one we already stored
 * once, pruned, and are now reading again. That article is not news, and it
 * must not push today's articles down.
 *
 * The two are told apart by the feed's own previous SUCCESSFUL fetch: an
 * article published before we last looked is one the feed was already
 * serving, so it keeps its publication date and sinks to where it belongs.
 * The window is per feed on purpose — a five-minute feed and a six-hour feed
 * disagree about how old "since we last looked" is, and a fixed window would
 * be wrong for both.
 *
 * "Successful" and not merely "previous" matters: a failed attempt still
 * advances the feed's lastFetchedAt (FeedScheduler::recordFailure(),
 * recordGone()), but it is not evidence about what the feed was serving. A
 * feed that errors for nine days and then recovers must give its whole
 * backlog the grace of "new to us" — using the failed attempts' timestamp
 * instead would sink that backlog to its own publication dates and make the
 * recovered feed look empty.
 *
 * Nothing ever outranks the fetch instant: a feed that declares tomorrow's date
 * would otherwise pin itself to the top of the list until tomorrow arrives.
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
