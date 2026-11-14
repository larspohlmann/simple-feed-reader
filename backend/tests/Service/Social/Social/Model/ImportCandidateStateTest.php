<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Service\Social\Model\ImportCandidateState;
use PHPUnit\Framework\TestCase;

final class ImportCandidateStateTest extends TestCase
{
    public function testNewRanksFirstThenSubscribedThenNoLongerFollowed(): void
    {
        self::assertSame(
            [0, 1, 2],
            array_map(
                static fn (ImportCandidateState $state): int => $state->listingRank(),
                [ImportCandidateState::New, ImportCandidateState::Subscribed, ImportCandidateState::NoLongerFollowed],
            ),
        );
    }
}
