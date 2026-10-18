<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Social\Pass\ReplyNode;
use App\Service\Social\ReplyThreadSource\ReplyThreadSourceInterface;

/** A Mastodon reply thread source that answers or fails as told, per direction; it records each ask. */
final class FakeReplyThreadSource implements ReplyThreadSourceInterface
{
    /** @var list<string> */
    public array $calls = [];

    private CommentsAnswerModel $accountAnswer;

    private CommentsAnswerModel $publicAnswer;

    private ?\Throwable $accountFailure = null;

    private ?\Throwable $publicFailure = null;

    /** @var list<ReplyNode> */
    private array $replies = [];

    private int $publicThrottleSeconds = 0;

    private ?\Throwable $repliesFailure = null;

    /** @var \Closure(): void */
    private \Closure $duringPublicAsk;

    public function __construct()
    {
        $this->accountAnswer = CommentsAnswerModel::settled('account thread');
        $this->publicAnswer = CommentsAnswerModel::settled('public thread');
        $this->duringPublicAsk = static function (): void {
        };
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function answersAsAccount(CommentsAnswerModel $answer): void
    {
        $this->accountAnswer = $answer;
    }

    public function answersInPublic(CommentsAnswerModel $answer): void
    {
        $this->publicAnswer = $answer;
    }

    public function failAsAccountWith(\Throwable $failure): void
    {
        $this->accountFailure = $failure;
    }

    public function failInPublicWith(\Throwable $failure): void
    {
        $this->publicFailure = $failure;
    }

    public function throttlePublicThreadFor(int $seconds): void
    {
        $this->publicThrottleSeconds = $seconds;
    }

    public function failRepliesWith(\Throwable $failure): void
    {
        $this->repliesFailure = $failure;
    }

    /** @param \Closure(): void $sideEffect what else happens while a public thread is asked for, say time passing */
    public function duringEachPublicAsk(\Closure $sideEffect): void
    {
        $this->duringPublicAsk = $sideEffect;
    }

    /** @param list<ReplyNode> $replies */
    public function readsReplies(array $replies): void
    {
        $this->replies = $replies;
    }

    public function asAccount(SocialAccount $account, string $address): CommentsAnswerModel
    {
        $this->calls[] = 'asAccount ' . $account->getHandle() . ' ' . $address;
        if (null !== $this->accountFailure) {
            throw $this->accountFailure;
        }

        return $this->accountAnswer;
    }

    public function inPublic(string $address): CommentsAnswerModel
    {
        $this->calls[] = 'inPublic ' . $address;
        ($this->duringPublicAsk)();
        if (null !== $this->publicFailure) {
            throw $this->publicFailure;
        }

        return $this->publicAnswer;
    }

    public function publicThreadThrottledForSeconds(): int
    {
        return $this->publicThrottleSeconds;
    }

    public function repliesIn(string $address, string $answer): array
    {
        $this->calls[] = 'repliesIn ' . $address . ' ' . $answer;
        if (null !== $this->repliesFailure) {
            throw $this->repliesFailure;
        }

        return $this->replies;
    }

    public function selfReplyOf(JsonNodeModel $post): EntryCommentModel
    {
        return new EntryCommentModel(
            new CommentAuthorModel('Fake'),
            $post->string('uri'),
            null,
            '<p>' . $post->string('uri') . '</p>',
            true,
        );
    }
}
