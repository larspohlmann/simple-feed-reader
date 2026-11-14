<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Social\Model\NetworkCollectionsModel;
use App\Service\Social\Social\SocialCandidates\SocialCandidatesInterface;
use Psr\Clock\ClockInterface;

/** Stamps the clock's reading under "sync" at the first question, then reports the network unreachable. */
final readonly class ClockStampingCandidates implements SocialCandidatesInterface
{
    public function __construct(private StageStamps $stamps, private ClockInterface $clock)
    {
    }

    public function candidates(SocialAccount $account): NetworkCandidatesModel
    {
        $this->stamp();

        throw new SocialNetworkUnreachableException('no network in this test');
    }

    public function collections(SocialAccount $account): NetworkCollectionsModel
    {
        $this->stamp();

        throw new SocialNetworkUnreachableException('no network in this test');
    }

    private function stamp(): void
    {
        $this->stamps->record('sync', $this->clock->now());
    }
}
