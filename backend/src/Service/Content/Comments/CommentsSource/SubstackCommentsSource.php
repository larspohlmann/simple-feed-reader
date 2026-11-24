<?php

declare(strict_types=1);

namespace App\Service\Content\Comments\CommentsSource;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Content\Comments\CommentBodyRenderer;
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
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Outbound\Fetch\Exception\FetchException;
use App\Service\Outbound\Fetch\FeedFetcher\FeedFetcherInterface;

final readonly class SubstackCommentsSource implements CommentsSourceInterface
{
    public function __construct(
        private FeedFetcherInterface $fetcher,
        private CommentsEndpointFetcher $endpointFetcher,
        private CommentBodyRenderer $bodies,
    ) {
    }

    public function serves(Entry $entry): bool
    {
        return null !== SubstackPostAddress::findPost($entry);
    }

    public function buildAudience(User $user, Entry $entry): CommentsAudienceModel
    {
        return new CommentsAudienceModel(self::requirePost($entry)->url());
    }

    public function fetch(CommentsRequest $request): CommentsAnswerModel
    {
        $post = self::requirePost($request->entry);
        $endpoint = new CommentsEndpointModel(
            $post->url(),
            'Substack asked us to wait.',
            'Substack could not be reached.',
        );
        $fetchComments = fn (): string => $this->body($post->commentsApiUrl($this->fetchPostId($post)));

        return CommentsAnswerModel::settled($this->endpointFetcher->guarded($endpoint, $request->user, $fetchComments));
    }

    public function read(CommentsRequest $request, string $answer): CommentsPageModel
    {
        $entry = $request->entry;
        $nodes = JsonNodeModel::fromValue(json_decode($answer, true))->listAt('comments')
            ?? throw new CommentsUnavailableException('Substack answered without a comment list.');

        $thread = new SubstackThread(self::requirePost($entry), $entry->getAuthor(), $this->bodies);

        return new CommentsPageModel($thread->comments($nodes));
    }

    /** @throws FetchException */
    private function fetchPostId(SubstackPostModel $post): int
    {
        return JsonNodeModel::fromValue(json_decode($this->body($post->postApiUrl()), true))->int('id')
            ?? throw new CommentsUnavailableException('Substack answered without a post id.');
    }

    /** @throws FetchException */
    private function body(string $url): string
    {
        return $this->fetcher->fetch($url)->modifiedBody();
    }

    private static function requirePost(Entry $entry): SubstackPostModel
    {
        return SubstackPostAddress::findPost($entry)
            ?? throw new CommentsUnavailableException('The entry is not a Substack post.');
    }
}
