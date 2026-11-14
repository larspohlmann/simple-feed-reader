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
use App\Service\Comments\Model\LemmyPostModel;
use App\Service\Comments\Pass\CommentsRequest;
use App\Service\Comments\Pass\LemmyThread;
use App\Service\Comments\Support\LemmyPostAddress;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Text\MarkdownHtml;

final readonly class LemmyCommentsSource implements CommentsSourceInterface
{
    public function __construct(
        private CommentsEndpointFetcher $endpointFetcher,
        private MarkdownHtml $markdown,
        private EntrySanitizer $sanitizer,
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
        $endpoint = new CommentsEndpointModel(
            $post->instanceUrl,
            'The Lemmy instance asked us to wait.',
            'The Lemmy instance could not be reached.',
        );

        $body = $this->endpointFetcher->body($post->commentsApiUrl(), $endpoint, $request->user);

        return CommentsAnswerModel::settled($body);
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
