<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Reading;

use App\Entity\EntryState;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Service\Library\Reading\SavedSearchMarkReadService;
use App\Tests\DbTestCase;
use App\Tests\Support\LemmyPostCopies;
use App\Tests\Support\RequiresServices;
use App\Tests\Support\SeedsUsers;

final class SavedSearchMarkReadServiceTest extends DbTestCase
{
    use RequiresServices;
    use SeedsUsers;

    public function testMarkingASearchLeavesNoCopyOfAMemberUnread(): void
    {
        $reader = $this->user('saved-search-copies@example.com');
        $copies = LemmyPostCopies::persistPair($this->entityManager, $reader);
        $search = new SavedSearch($reader, 'post', false);
        $this->entityManager->persist($search);
        $this->entityManager->persist(
            new SavedSearchEntry($search, $copies->localCopy, new \DateTimeImmutable('2026-11-14T10:00:00')),
        );
        $this->entityManager->flush();

        $this->requireService()->markOne($reader, $search->requireId(), new \DateTimeImmutable('2026-11-15T00:00:00Z'));

        $this->entityManager->clear();
        $copyState = $this->entityManager->getRepository(EntryState::class)
            ->findOneForUserEntry($reader->requireId(), $copies->allCopy->requireId());
        self::assertTrue($copyState?->isHidden());
    }

    private function requireService(): SavedSearchMarkReadService
    {
        return $this->requireFromContainer(SavedSearchMarkReadService::class);
    }
}
