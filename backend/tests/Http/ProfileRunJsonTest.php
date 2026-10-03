<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\ProfileRun;
use App\Entity\User;
use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Http\ProfileRunJson;
use PHPUnit\Framework\TestCase;

final class ProfileRunJsonTest extends TestCase
{
    public function testACompletedRunCarriesItsOutcomeAndItsModel(): void
    {
        $profileRun = new ProfileRun(
            new User('profile-json@example.test', new \DateTimeImmutable('2026-10-01 06:00:00')),
            ProfileRunTrigger::Scheduled,
            new \DateTimeImmutable('2026-10-03 09:00:00'),
        );
        $profileRun->start('fingerprint', 'llm.example.test', 'qwen3-14b');
        $profileRun->complete(ProfileRunOutcome::Unchanged, new \DateTimeImmutable('2026-10-03 09:00:02'));

        $json = ProfileRunJson::current($profileRun);

        self::assertSame('completed', $json['status']);
        self::assertSame('scheduled', $json['trigger']);
        self::assertSame('unchanged', $json['outcome']);
        self::assertSame('qwen3-14b', $json['model']);
        self::assertSame('2026-10-03T09:00:02+00:00', $json['completedAt']);
        self::assertSame(3, $json['maxAttempts']);
    }

    public function testNoRunReadsAsNone(): void
    {
        $json = ProfileRunJson::current(null);

        self::assertSame('none', $json['status']);
        self::assertNull($json['outcome']);
    }
}
