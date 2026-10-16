<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Comments\CommentsSource\CommentsSourceInterface;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Comments\Model\CommentsPageModel;
use App\Service\Comments\Model\EntryCommentModel;

final class FakeCommentsSource implements CommentsSourceInterface
{
    /** @var list<string> the guids of the entries fetched, in order */
    public array $fetches = [];

    public bool $servesEntries = true;

    private ?\Throwable $failure = null;

    private bool $nextIsStillGathering = false;

    public function __construct(private readonly string $answer)
    {
    }

    public function failWith(\Throwable $failure): void
    {
        $this->failure = $failure;
    }

    public function recover(): void
    {
        $this->failure = null;
    }

    public function stillGathering(): void
    {
        $this->nextIsStillGathering = true;
    }

    public function serves(Entry $entry): bool
    {
        return $this->servesEntries;
    }

    public function audienceOf(User $user, Entry $entry): string
    {
        return 'fake:' . $entry->getGuid();
    }

    public function fetch(User $user, Entry $entry): CommentsAnswerModel
    {
        $this->fetches[] = $entry->getGuid();
        if (null !== $this->failure) {
            throw $this->failure;
        }
        $body = $this->answer . '-' . \count($this->fetches);
        if ($this->nextIsStillGathering) {
            $this->nextIsStillGathering = false;

            return CommentsAnswerModel::stillGathering($body);
        }

        return CommentsAnswerModel::settled($body);
    }

    public function read(Entry $entry, string $answer): CommentsPageModel
    {
        return new CommentsPageModel([
            new EntryCommentModel(new CommentAuthorModel('Reader'), null, null, $answer, false),
        ]);
    }
}
