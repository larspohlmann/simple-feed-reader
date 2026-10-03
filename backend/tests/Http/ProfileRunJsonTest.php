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

    public function testARunShowsEveryField(): void
    {
        $profileRun = new ProfileRun(
            new User('profile-json@example.test', new \DateTimeImmutable('2026-10-01 06:00:00')),
            ProfileRunTrigger::Manual,
            new \DateTimeImmutable('2026-10-03 09:00:00'),
        );
        $profileRun->start('fingerprint', 'llm.example.test', 'qwen3-14b');
        $profileRun->recordInvalidReply('not json');
        $profileRun->recordTransportFailure();
        $profileRun->fail('The AI provider failed.', new \DateTimeImmutable('2026-10-03 09:00:05'));

        self::assertSame([
            'status' => 'failed',
            'id' => null,
            'trigger' => 'manual',
            'outcome' => null,
            'error' => 'The AI provider failed.',
            'createdAt' => '2026-10-03T09:00:00+00:00',
            'completedAt' => '2026-10-03T09:00:05+00:00',
            'providerHost' => 'llm.example.test',
            'model' => 'qwen3-14b',
            'attempts' => 1,
            'maxAttempts' => 3,
            'transportFailures' => 1,
            'maxTransportFailures' => 3,
            'streamedChars' => 0,
        ], ProfileRunJson::current($profileRun));
    }

    public function testNoRunHasEveryFieldEmpty(): void
    {
        self::assertSame([
            'status' => 'none',
            'id' => null,
            'trigger' => null,
            'outcome' => null,
            'error' => null,
            'createdAt' => null,
            'completedAt' => null,
            'providerHost' => null,
            'model' => null,
            'attempts' => 0,
            'maxAttempts' => 3,
            'transportFailures' => 0,
            'maxTransportFailures' => 3,
            'streamedChars' => 0,
        ], ProfileRunJson::current(null));
    }
}
