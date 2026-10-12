<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\SocialCandidates\SocialCandidatesInterface;

final class StubSocialCandidates implements SocialCandidatesInterface
{
    public int $calls = 0;

    public function __construct(private readonly NetworkCandidatesModel|\Throwable $outcome)
    {
    }

    public function candidates(SocialAccount $account): NetworkCandidatesModel
    {
        ++$this->calls;
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}
