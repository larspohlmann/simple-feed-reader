<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Comments\CommentsSource\CommentsSourceInterface;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Comments\Model\CommentsPageModel;
use App\Service\Comments\Model\EntryCommentModel;

final class FakeCommentsSource implements CommentsSourceInterface
{
    /** @var list<string> the guids of the entries fetched, in order */
    public array $fetches = [];

    public bool $servesEntries = true;

    public int $audienceAsks = 0;

    private ?\Throwable $failure = null;

    private bool $nextIsStillGathering = false;

    /** @var list<string> */
    private array $unreadableAnswers = [];

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

    public function cannotRead(string ...$answers): void
    {
        $this->unreadableAnswers = array_values($answers);
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
        ++$this->audienceAsks;

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
        if (\in_array($answer, $this->unreadableAnswers, true)) {
            throw new CommentsUnavailableException('unreadable');
        }

        return new CommentsPageModel([
            new EntryCommentModel(new CommentAuthorModel('Reader'), null, null, $answer, false),
        ]);
    }
}
