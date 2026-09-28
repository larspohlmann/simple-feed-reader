<?php

declare(strict_types=1);

namespace App\Service\Comments;

use App\Entity\Entry;
use App\Service\Comments\Exception\NoCommentsFeedException;
use App\Service\Comments\Model\CommentsResultModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\HostThrottle;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;
use App\Service\Parser\Model\ParsedEntryModel;
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

    public function load(Entry $entry): CommentsResultModel
    {
        $feedUrl = $entry->getDiscussion()->commentsFeedUrl
            ?? throw new NoCommentsFeedException('The entry has no comments feed.');

        $wait = $this->hostThrottle->remainingSeconds($feedUrl);
        if ($wait > 0) {
            return CommentsResultModel::throttled($wait);
        }

        try {
            $body = $this->fetcher->fetch($feedUrl)->modifiedBody();

            return CommentsResultModel::ok($this->comments($entry, $this->parser->parse($body)->entries));
        } catch (FeedThrottledException $e) {
            $wait = $this->hostThrottle->record($feedUrl, $e->retryAfterSeconds);

            return CommentsResultModel::throttled($wait);
        } catch (FetchException | FeedParseException) {
            return CommentsResultModel::failed();
        }
    }

    /**
     * @param list<ParsedEntryModel> $parsed
     *
     * @return list<EntryCommentModel>
     */
    private function comments(Entry $entry, array $parsed): array
    {
        $comments = [];
        foreach ($parsed as $item) {
            if ($item->guid === $entry->getGuid()) {
                continue;
            }
            $comments[] = new EntryCommentModel(
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
