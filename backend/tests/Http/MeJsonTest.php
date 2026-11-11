<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\User;
use App\Enum\DigestCadence;
use App\Http\MeJson;
use App\Tests\Support\UnsavedUserSettings;
use PHPUnit\Framework\TestCase;

final class MeJsonTest extends TestCase
{
    private function user(): User
    {
        return new User('reader@example.test', new \DateTimeImmutable('2026-08-09 09:00:00'));
    }

    public function testTheProfileEmitsMailAndVerificationAndThePreferencesTheDigest(): void
    {
        $user = $this->user();
        $user->markEmailVerified(new \DateTimeImmutable('2026-08-09 09:10:00'));
        $settings = UnsavedUserSettings::of($user);
        $settings->digest->setEnabled(true);
        $settings->digest->setCadence(DigestCadence::Weekly);
        $settings->digest->setSendHour(9);
        $settings->digest->setWeekday(3);

        $profile = MeJson::profile($user, true);
        $preferencesJson = MeJson::preferences($settings, false, 'Europe/Berlin');

        self::assertSame(['enabled' => true], $profile['mail']);
        self::assertTrue($profile['emailVerified']);
        self::assertSame(
            [
                'enabled' => true,
                'cadence' => 'weekly',
                'format' => 'html',
                'sendHour' => 9,
                'weekday' => 3,
                'timezone' => 'Europe/Berlin',
            ],
            $preferencesJson['digest'],
        );
    }
}
