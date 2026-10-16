<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Social\Model\ReplyNodeModel;
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

    /** @var list<ReplyNodeModel> */
    private array $replies = [];

    public function __construct()
    {
        $this->accountAnswer = CommentsAnswerModel::settled('account thread');
        $this->publicAnswer = CommentsAnswerModel::settled('public thread');
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

    /** @param list<ReplyNodeModel> $replies */
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
        if (null !== $this->publicFailure) {
            throw $this->publicFailure;
        }

        return $this->publicAnswer;
    }

    public function repliesIn(string $address, string $answer): array
    {
        $this->calls[] = 'repliesIn ' . $address . ' ' . $answer;

        return $this->replies;
    }
}
