<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Run;

use App\Entity\User;
use App\Service\Curation\Recommendation\Run\TickLockTtl;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class TickLockTtlTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = RecommendationRunFixtures::fromContainer(self::getContainer());
        $this->owner = $this->user('tick-lock-ttl@example.test');
    }

    public function testAnAccountWithoutAConnectionGetsTheStandardBound(): void
    {
        self::assertSame(180.0 + TickLockTtl::MARGIN_SECONDS, $this->ttl()->computeSeconds($this->owner));
    }

    public function testASlowActiveConnectionGetsTheSlowBound(): void
    {
        $this->fixtures->seedReadyScoringSettings($this->owner);
        $this->owner->getActiveAiProviderSettings()?->setSlowModel(true);
        $this->entityManager->flush();

        self::assertSame(900.0 + TickLockTtl::MARGIN_SECONDS, $this->ttl()->computeSeconds($this->owner));
    }

    private function ttl(): TickLockTtl
    {
        /** @var TickLockTtl $ttl */
        $ttl = self::getContainer()->get(TickLockTtl::class);

        return $ttl;
    }
}
