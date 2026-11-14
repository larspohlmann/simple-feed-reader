<?php

declare(strict_types=1);

namespace App\Service\Comments\CommentsSource;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Comments\Exception\CommentsThrottledException;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Comments\Model\CommentsAudienceModel;
use App\Service\Comments\Model\CommentsPageModel;
use App\Service\Comments\Model\LemmyPostModel;
use App\Service\Comments\Pass\CommentsRequest;
use App\Service\Comments\Pass\LemmyThread;
use App\Service\Comments\Support\LemmyPostAddress;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\HostThrottle;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Text\MarkdownHtml;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class LemmyCommentsSource implements CommentsSourceInterface
{
    public function __construct(
        private FeedFetcherInterface $fetcher,
        private MarkdownHtml $markdown,
        private EntrySanitizer $sanitizer,
        private HostThrottle $hostThrottle,
        private RateLimitGuard $rateLimitGuard,
        #[Target]
        private RateLimiterFactoryInterface $commentsLimiter,
    ) {
    }

    public function serves(Entry $entry): bool
    {
        return null !== LemmyPostAddress::of($entry);
    }

    public function audienceOf(User $user, Entry $entry): CommentsAudienceModel
    {
        return new CommentsAudienceModel(self::postOf($entry)->pageUrl());
    }

    public function fetch(CommentsRequest $request): CommentsAnswerModel
    {
        $post = self::postOf($request->entry);
        $wait = $this->hostThrottle->remainingSeconds($post->instanceUrl);
        if ($wait > 0) {
            throw new CommentsThrottledException('The Lemmy instance asked us to wait.', $wait);
        }
        $this->rateLimitGuard->enforceForUser($this->commentsLimiter, $request->user);

        try {
            return CommentsAnswerModel::settled($this->fetcher->fetch($post->commentsApiUrl())->modifiedBody());
        } catch (FeedThrottledException $exception) {
            $wait = $this->hostThrottle->record($post->instanceUrl, $exception->retryAfterSeconds);

            throw new CommentsThrottledException('The Lemmy instance asked us to wait.', $wait, $exception);
        } catch (FetchException $exception) {
            throw new CommentsUnavailableException('The Lemmy instance could not be reached.', previous: $exception);
        }
    }

    public function read(CommentsRequest $request, string $answer): CommentsPageModel
    {
        $nodes = JsonNodeModel::of(json_decode($answer, true))->listAt('comments')
            ?? throw new CommentsUnavailableException('The Lemmy instance answered without a comment list.');
        $thread = new LemmyThread($request->entry->getAuthorUrl(), $this->markdown, $this->sanitizer);

        return new CommentsPageModel($thread->comments($nodes));
    }

    private static function postOf(Entry $entry): LemmyPostModel
    {
        return LemmyPostAddress::of($entry)
            ?? throw new CommentsUnavailableException('The entry is not a Lemmy post.');
    }
}
