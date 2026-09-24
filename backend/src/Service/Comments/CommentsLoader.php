<?php

declare(strict_types=1);

namespace App\Service\Comments;

use App\Entity\Entry;
use App\Service\Comments\Exception\NoCommentsFeedException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\HostThrottle;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;
use App\Service\Parser\ParsedEntry;
use App\Service\Sanitize\EntrySanitizer;

final readonly class CommentsLoader
{
    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedParser $parser,
        private EntrySanitizer $sanitizer,
        private HostThrottle $hostThrottle,
    ) {
    }

    public function load(Entry $entry): CommentsResult
    {
        $feedUrl = $entry->getDiscussion()->commentsFeedUrl
            ?? throw new NoCommentsFeedException('The entry has no comments feed.');

        $wait = $this->hostThrottle->remainingSeconds($feedUrl);
        if ($wait > 0) {
            return CommentsResult::throttled($wait);
        }

        try {
            $body = (string) $this->fetcher->fetch($feedUrl)->body;

            return CommentsResult::ok($this->comments($entry, $this->parser->parse($body)->entries));
        } catch (FeedThrottledException $e) {
            $wait = $this->hostThrottle->record($feedUrl, $e->retryAfterSeconds);

            return CommentsResult::throttled($wait);
        } catch (FetchException | FeedParseException) {
            return CommentsResult::failed();
        }
    }

    /**
     * @param list<ParsedEntry> $parsed
     *
     * @return list<EntryComment>
     */
    private function comments(Entry $entry, array $parsed): array
    {
        $comments = [];
        foreach ($parsed as $item) {
            if ($item->guid === $entry->getGuid()) {
                continue;
            }
            $comments[] = new EntryComment(
                $item->author,
                $item->authorUrl,
                $item->url,
                $item->publishedAt,
                (string) $this->sanitizer->sanitize($item->contentHtml),
                $item->author !== null && $item->author === $entry->getAuthor(),
            );
        }

        return $comments;
    }
}
