<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\NetworkCollectionsModel;
use App\Service\Social\SocialCandidates\SocialCandidatesInterface;

final class StubSocialCandidates implements SocialCandidatesInterface
{
    public int $calls = 0;

    /**
     * @param NetworkCandidatesModel|\Throwable       $outcome     what candidates() answers
     * @param NetworkCollectionsModel|\Throwable|null $collections what collections() answers; null reuses $outcome
     */
    public function __construct(
        private readonly NetworkCandidatesModel|\Throwable $outcome,
        private readonly NetworkCollectionsModel|\Throwable|null $collections = null,
    ) {
    }

    public function candidates(SocialAccount $account): NetworkCandidatesModel
    {
        ++$this->calls;
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }

    public function collections(SocialAccount $account): NetworkCollectionsModel
    {
        ++$this->calls;
        $answer = $this->collections ?? $this->outcome;
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer instanceof NetworkCollectionsModel
            ? $answer
            : new NetworkCollectionsModel(
                $answer->lists,
                $answer->customFeeds,
                truncated: $answer->truncated,
                tags: $answer->tags,
            );
    }
}
