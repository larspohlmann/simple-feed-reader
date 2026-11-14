<?php

declare(strict_types=1);

namespace App\Service\Comments\CommentsSource;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Comments\CommentsEndpointFetcher;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Comments\Model\CommentsAudienceModel;
use App\Service\Comments\Model\CommentsEndpointModel;
use App\Service\Comments\Model\CommentsPageModel;
use App\Service\Comments\Model\SubstackPostModel;
use App\Service\Comments\Pass\CommentsRequest;
use App\Service\Comments\Pass\SubstackThread;
use App\Service\Comments\Support\SubstackPostAddress;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;

final readonly class SubstackCommentsSource implements CommentsSourceInterface
{
    public function __construct(
        private FeedFetcherInterface $fetcher,
        private CommentsEndpointFetcher $endpointFetcher,
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
        $endpoint = new CommentsEndpointModel(
            $post->url(),
            'Substack asked us to wait.',
            'Substack could not be reached.',
        );
        $commentsOf = fn (): string => $this->body($post->commentsApiUrl($this->postIdOf($post)));

        return CommentsAnswerModel::settled($this->endpointFetcher->guarded($endpoint, $request->user, $commentsOf));
    }

    public function read(CommentsRequest $request, string $answer): CommentsPageModel
    {
        $entry = $request->entry;
        $nodes = JsonNodeModel::of(json_decode($answer, true))->listAt('comments')
            ?? throw new CommentsUnavailableException('Substack answered without a comment list.');

        return new CommentsPageModel(new SubstackThread(self::postOf($entry), $entry->getAuthor())->comments($nodes));
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
