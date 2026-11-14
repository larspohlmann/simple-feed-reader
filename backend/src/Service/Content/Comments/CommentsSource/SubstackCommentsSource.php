<?php

declare(strict_types=1);

namespace App\Service\Content\Comments\CommentsSource;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Content\Comments\CommentsEndpointFetcher;
use App\Service\Content\Comments\Exception\CommentsUnavailableException;
use App\Service\Content\Comments\Model\CommentsAnswerModel;
use App\Service\Content\Comments\Model\CommentsAudienceModel;
use App\Service\Content\Comments\Model\CommentsEndpointModel;
use App\Service\Content\Comments\Model\CommentsPageModel;
use App\Service\Content\Comments\Model\SubstackPostModel;
use App\Service\Content\Comments\Pass\CommentsRequest;
use App\Service\Content\Comments\Pass\SubstackThread;
use App\Service\Content\Comments\Support\SubstackPostAddress;
use App\Service\Outbound\Fetch\Exception\FetchException;
use App\Service\Outbound\Fetch\FeedFetcher\FeedFetcherInterface;

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
