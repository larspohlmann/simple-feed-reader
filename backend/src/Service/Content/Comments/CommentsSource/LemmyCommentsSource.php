<?php

declare(strict_types=1);

namespace App\Service\Content\Comments\CommentsSource;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Content\Comments\CommentsEndpointFetcher;
use App\Service\Content\Comments\Exception\CommentsThrottledException;
use App\Service\Content\Comments\Exception\CommentsUnavailableException;
use App\Service\Content\Comments\LemmyThreadReader\LemmyThreadReaderInterface;
use App\Service\Content\Comments\Model\CommentsAnswerModel;
use App\Service\Content\Comments\Model\CommentsAudienceModel;
use App\Service\Content\Comments\Model\CommentsEndpointModel;
use App\Service\Content\Comments\Model\CommentsPageModel;
use App\Service\Content\Comments\Model\LemmyPostModel;
use App\Service\Content\Comments\Pass\CommentsRequest;
use App\Service\Content\Comments\Pass\LemmyThread;
use App\Service\Content\Comments\Support\LemmyPostAddress;
use App\Service\Foundation\Sanitize\EntrySanitizer;
use App\Service\Foundation\Text\MarkdownHtml;

final readonly class LemmyCommentsSource implements CommentsSourceInterface
{
    public function __construct(
        private CommentsEndpointFetcher $endpointFetcher,
        private MarkdownHtml $markdown,
        private EntrySanitizer $sanitizer,
        private LemmyThreadReaderInterface $threadReader,
    ) {
    }

    public function serves(Entry $entry): bool
    {
        return null !== LemmyPostAddress::of($entry);
    }

    public function audienceOf(User $user, Entry $entry): CommentsAudienceModel
    {
        $pageUrl = self::postOf($entry)->pageUrl();
        $account = $this->threadReader->readerFor($user);

        return null === $account
            ? new CommentsAudienceModel($pageUrl)
            : new CommentsAudienceModel($pageUrl . ' account:' . $account->requireId(), $account);
    }

    public function fetch(CommentsRequest $request): CommentsAnswerModel
    {
        $post = self::postOf($request->entry);
        $account = $request->audience->actingAccount;
        if (null !== $account) {
            try {
                return CommentsAnswerModel::settled($this->threadReader->threadAs($account, $post));
            } catch (CommentsUnavailableException | CommentsThrottledException) {
            }
        }

        return $this->anonymousThread($request, $post);
    }

    private function anonymousThread(CommentsRequest $request, LemmyPostModel $post): CommentsAnswerModel
    {
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
        if (null !== $request->audience->actingAccount) {
            $thread = $thread->forVoter();
        }

        return new CommentsPageModel($thread->comments($nodes));
    }

    private static function postOf(Entry $entry): LemmyPostModel
    {
        return LemmyPostAddress::of($entry)
            ?? throw new CommentsUnavailableException('The entry is not a Lemmy post.');
    }
}
