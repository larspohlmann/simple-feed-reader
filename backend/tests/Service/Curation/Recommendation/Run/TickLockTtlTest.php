<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Run\TickLockTtl;
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

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('tick-lock-ttl@example.test');
    }

    public function testAnAccountWithoutAConnectionGetsTheStandardBound(): void
    {
        self::assertSame(180.0 + TickLockTtl::MARGIN_SECONDS, $this->ttl()->secondsFor($this->owner));
    }

    public function testASlowActiveConnectionGetsTheSlowBound(): void
    {
        $this->fixtures->seedReadyScoringSettings($this->owner);
        $this->owner->getActiveAiProviderSettings()?->setSlowModel(true);
        $this->entityManager->flush();

        self::assertSame(900.0 + TickLockTtl::MARGIN_SECONDS, $this->ttl()->secondsFor($this->owner));
    }

    private function ttl(): TickLockTtl
    {
        /** @var TickLockTtl $ttl */
        $ttl = self::getContainer()->get(TickLockTtl::class);

        return $ttl;
    }
}
