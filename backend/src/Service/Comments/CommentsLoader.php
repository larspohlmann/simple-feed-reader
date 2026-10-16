<?php

declare(strict_types=1);

namespace App\Service\Comments;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Comments\CommentsSource\CommentsSourceInterface;
use App\Service\Comments\Exception\CommentsThrottledException;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Exception\NoCommentsFeedException;
use App\Service\Comments\Model\CachedCommentsModel;
use App\Service\Comments\Model\CommentsResultModel;
use App\Service\Comments\Pass\CommentsRequest;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class CommentsLoader
{
    /** @param iterable<CommentsSourceInterface> $sources */
    public function __construct(
        #[AutowireIterator('app.comments_source')]
        private iterable $sources,
        private CommentsCache $cache,
        private ClockInterface $clock,
    ) {
    }

    /** @throws NoCommentsFeedException when no source serves the entry */
    public function load(User $user, Entry $entry): CommentsResultModel
    {
        $request = new CommentsRequest($this->sourceFor($entry), $user, $entry);
        $cached = $this->cache->find($request->cacheKey());

        return null === $cached ? $this->fetched($request) : $this->cachedOrFetched($request, $cached);
    }

    /** As load(), but asks the source again whatever is cached. */
    public function refreshed(User $user, Entry $entry): CommentsResultModel
    {
        return $this->fetched(new CommentsRequest($this->sourceFor($entry), $user, $entry));
    }

    private function fetched(CommentsRequest $request): CommentsResultModel
    {
        try {
            $answer = $request->source->fetch($request->user, $request->entry);
            $page = $request->source->read($request->entry, $answer->body);
        } catch (CommentsThrottledException $exception) {
            return CommentsResultModel::throttled($exception->retryAfterSeconds);
        } catch (CommentsUnavailableException) {
            return CommentsResultModel::failed();
        }
        $fetchedAt = $this->clock->now();
        $this->cache->remember($request->cacheKey(), $answer, $fetchedAt);

        return CommentsResultModel::ok($page, $fetchedAt);
    }

    private function cachedOrFetched(CommentsRequest $request, CachedCommentsModel $cached): CommentsResultModel
    {
        try {
            return CommentsResultModel::ok($request->source->read($request->entry, $cached->body), $cached->fetchedAt);
        } catch (CommentsUnavailableException) {
            return $this->fetched($request);
        }
    }

    private function sourceFor(Entry $entry): CommentsSourceInterface
    {
        foreach ($this->sources as $source) {
            if ($source->serves($entry)) {
                return $source;
            }
        }

        throw new NoCommentsFeedException('No comments source serves the entry.');
    }
}
