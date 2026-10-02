<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\User;
use App\Enum\DigestCadence;
use App\Http\MeJson;
use PHPUnit\Framework\TestCase;

final class MeJsonTest extends TestCase
{
    private function user(): User
    {
        return new User('reader@example.test', new \DateTimeImmutable('2026-08-09 09:00:00'));
    }

    public function testProfileEmitsMailDigestAndVerification(): void
    {
        $user = $this->user();
        $user->markEmailVerified(new \DateTimeImmutable('2026-08-09 09:10:00'));
        $preferences = $user->getPreferences();
        $preferences->setDigestEnabled(true);
        $preferences->setDigestCadence(DigestCadence::Weekly);
        $preferences->setDigestSendHour(9);
        $preferences->setDigestWeekday(3);

        $profile = MeJson::profile($user, true, 'Europe/Berlin');

        self::assertSame(['enabled' => true], $profile['mail']);
        self::assertTrue($profile['emailVerified']);
        self::assertIsArray($profile['preferences']);
        self::assertSame(
            [
                'enabled' => true,
                'cadence' => 'weekly',
                'format' => 'html',
                'sendHour' => 9,
                'weekday' => 3,
                'timezone' => 'Europe/Berlin',
            ],
            $profile['preferences']['digest'],
        );
    }
}
