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
use App\Service\Comments\Model\SubstackPostModel;
use App\Service\Comments\Pass\CommentsRequest;
use App\Service\Comments\Pass\SubstackThread;
use App\Service\Comments\Support\SubstackPostAddress;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\HostThrottle;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class SubstackCommentsSource implements CommentsSourceInterface
{
    public function __construct(
        private FeedFetcherInterface $fetcher,
        private HostThrottle $hostThrottle,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $commentsLimiter,
    ) {
    }

    public function serves(Entry $entry): bool
    {
        return null !== SubstackPostAddress::of($entry);
    }

    public function audienceOf(User $user, Entry $entry): CommentsAudienceModel
    {
        return new CommentsAudienceModel(self::postOf($entry)->url());
    }

    public function fetch(CommentsRequest $request): CommentsAnswerModel
    {
        $post = self::postOf($request->entry);
        $wait = $this->hostThrottle->remainingSeconds($post->url());
        if ($wait > 0) {
            throw new CommentsThrottledException('Substack asked us to wait.', $wait);
        }
        $this->rateLimitGuard->enforceForUser($this->commentsLimiter, $request->user);

        try {
            return CommentsAnswerModel::settled($this->body($post->commentsApiUrl($this->postIdOf($post))));
        } catch (FeedThrottledException $exception) {
            $wait = $this->hostThrottle->record($post->url(), $exception->retryAfterSeconds);

            throw new CommentsThrottledException('Substack asked us to wait.', $wait, $exception);
        } catch (FetchException $exception) {
            throw new CommentsUnavailableException('Substack could not be reached.', previous: $exception);
        }
    }

    public function read(CommentsRequest $request, string $answer): CommentsPageModel
    {
        $entry = $request->entry;
        $nodes = JsonNodeModel::of(json_decode($answer, true))->listAt('comments')
            ?? throw new CommentsUnavailableException('Substack answered without a comment list.');

        return new CommentsPageModel((new SubstackThread(self::postOf($entry), $entry->getAuthor()))->comments($nodes));
    }

    /** @throws FetchException */
    private function postIdOf(SubstackPostModel $post): int
    {
        return JsonNodeModel::of(json_decode($this->body($post->postApiUrl()), true))->int('id')
            ?? throw new CommentsUnavailableException('Substack answered without a post id.');
    }

    /** @throws FetchException */
    private function body(string $url): string
    {
        return $this->fetcher->fetch($url)->modifiedBody();
    }

    private static function postOf(Entry $entry): SubstackPostModel
    {
        return SubstackPostAddress::of($entry)
            ?? throw new CommentsUnavailableException('The entry is not a Substack post.');
    }
}
