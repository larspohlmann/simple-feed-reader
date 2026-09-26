<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CallOutcome;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class RecommendationRunLogTest extends TestCase
{
    public function testFinishingStoresTheReplyAndEveryPartOfTheOutcome(): void
    {
        $log = new RecommendationRunLog(
            $this->newRun(),
            RecommendationRunLog::PHASE_BATCH,
            2,
            1,
            'request body',
            new \DateTimeImmutable('2026-08-08T10:00:00Z'),
        );

        $log->finish(
            'reply text',
            new CallOutcome(
                RecommendationRunLog::VERDICT_UNUSABLE,
                4_096,
                new \DateTimeImmutable('2026-08-08T10:00:07Z'),
                'length',
            ),
        );

        self::assertSame('reply text', $log->getResponseText());
        self::assertSame(RecommendationRunLog::VERDICT_UNUSABLE, $log->getVerdict());
        self::assertSame(4_096, $log->getWireBytes());
        self::assertEquals(new \DateTimeImmutable('2026-08-08T10:00:07Z'), $log->getFinishedAt());
        self::assertSame('length', $log->getFinishReason());
    }

    private function newRun(): RecommendationRun
    {
        $user = new User('run-log@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));

        return new RecommendationRun($user, new \DateTimeImmutable('2026-08-08T09:59:00Z'));
    }
}
