<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Reading;

use App\Entity\EntryState;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Enum\RecommendationEngineKind;
use App\Service\Library\Reading\ForYouMarkReadService;
use App\Tests\DbTestCase;
use App\Tests\Support\LemmyPostCopies;
use App\Tests\Support\RequiresServices;
use App\Tests\Support\SeedsUsers;

final class ForYouMarkReadServiceTest extends DbTestCase
{
    use RequiresServices;
    use SeedsUsers;

    public function testMarkingThePicksLeavesNoCopyUnread(): void
    {
        $reader = $this->user('for-you-copies@example.com');
        $copies = LemmyPostCopies::persistPair($this->entityManager, $reader);
        $run = new RecommendationRun($reader, new \DateTimeImmutable('2026-11-15T09:00:00Z'));
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);
        $run->complete(new \DateTimeImmutable('2026-11-15T09:05:00Z'));
        $this->entityManager->persist($run);
        $this->entityManager->persist(new RecommendationItem($run, $copies->localCopy, 1, 'reason', 50));
        $this->entityManager->flush();

        $this->requireService()->mark($reader, new \DateTimeImmutable('2026-11-15T12:00:00Z'));

        $this->entityManager->clear();
        $copyState = $this->entityManager->getRepository(EntryState::class)
            ->findOneForUserEntry($reader->requireId(), $copies->allCopy->requireId());
        self::assertTrue($copyState?->isHidden());
    }

    private function requireService(): ForYouMarkReadService
    {
        return $this->requireFromContainer(ForYouMarkReadService::class);
    }
}
