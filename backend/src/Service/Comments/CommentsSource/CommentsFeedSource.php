<?php

declare(strict_types=1);

namespace App\Service\Comments\CommentsSource;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Comments\Exception\CommentsThrottledException;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Comments\Model\CommentsPageModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\HostThrottle;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Sanitize\EntrySanitizer;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class CommentsFeedSource implements CommentsSourceInterface
{
    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedParser $parser,
        private EntrySanitizer $sanitizer,
        private HostThrottle $hostThrottle,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $commentsLimiter,
    ) {
    }

    public function serves(Entry $entry): bool
    {
        return null !== $entry->getDiscussion()->commentsFeedUrl;
    }

    public function audienceOf(User $user, Entry $entry): string
    {
        return $entry->getDiscussion()->commentsFeedUrl ?? '';
    }

    public function fetch(User $user, Entry $entry): CommentsAnswerModel
    {
        $feedUrl = self::feedUrlOf($entry);
        $wait = $this->hostThrottle->remainingSeconds($feedUrl);
        if ($wait > 0) {
            throw new CommentsThrottledException('The comments feed host asked us to wait.', $wait);
        }
        $this->rateLimitGuard->enforceForUser($this->commentsLimiter, $user);

        try {
            return CommentsAnswerModel::settled($this->fetcher->fetch($feedUrl)->modifiedBody());
        } catch (FeedThrottledException $exception) {
            $wait = $this->hostThrottle->record($feedUrl, $exception->retryAfterSeconds);

            throw new CommentsThrottledException('The comments feed host asked us to wait.', $wait, $exception);
        } catch (FetchException $exception) {
            throw new CommentsUnavailableException('The comments feed could not be fetched.', 0, $exception);
        }
    }

    public function read(Entry $entry, string $answer): CommentsPageModel
    {
        try {
            $parsed = $this->parser->parse($answer)->entries;
        } catch (FeedParseException $exception) {
            throw new CommentsUnavailableException('The comments feed could not be parsed.', 0, $exception);
        }

        return new CommentsPageModel($this->comments($entry, $parsed));
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
                new CommentAuthorModel($item->author, null, $item->authorUrl, $item->authorAvatarUrl),
                $item->url,
                $item->publishedAt,
                (string) $this->sanitizer->sanitize($item->contentHtml),
                $item->author !== null && $item->author === $entry->getAuthor(),
            );
        }

        return $comments;
    }

    private static function feedUrlOf(Entry $entry): string
    {
        return $entry->getDiscussion()->commentsFeedUrl
            ?? throw new CommentsUnavailableException('The entry has no comments feed.');
    }
}
