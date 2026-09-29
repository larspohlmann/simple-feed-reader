<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Enum\DigestCadence;
use App\Http\MeJson;
use App\Tests\Support\AiProviderSettingsFactory;
use PHPUnit\Framework\TestCase;

/** MeJson's `ai` block reads an association, not a column: it must report the ACTIVE configuration, not just any. */
final class MeJsonTest extends TestCase
{
    private function user(): User
    {
        return new User('reader@example.test', new \DateTimeImmutable('2026-08-09 09:00:00'));
    }

    private function configuration(User $user, string $name): AiProviderSettings
    {
        return AiProviderSettingsFactory::build($user, $name);
    }

    public function testAnAccountWithNoActiveConfigurationIsNotReady(): void
    {
        $profile = MeJson::profile($this->user(), true, 'UTC');

        self::assertIsArray($profile['ai']);
        self::assertFalse($profile['ai']['ready']);
        self::assertNull($profile['ai']['model']);
    }

    public function testTheProfileReflectsTheActiveConfigurationsModel(): void
    {
        $user = $this->user();
        $active = $this->configuration($user, 'Work OpenAI');
        $active->chooseModel('gpt-4o-mini', new \DateTimeImmutable('2026-08-09T09:05:00Z'), null);
        $user->setActiveAiProviderSettings($active);

        $profile = MeJson::profile($user, true, 'UTC');

        self::assertIsArray($profile['ai']);
        self::assertTrue($profile['ai']['ready']);
        self::assertSame('gpt-4o-mini', $profile['ai']['model']);
    }

    /**
     * A second, fully verified configuration that never became active must not change the reported state; the
     * single-configuration case above cannot tell "the active one" from "any".
     */
    public function testASecondNonActiveConfigurationDoesNotChangeTheReportedState(): void
    {
        $user = $this->user();
        $active = $this->configuration($user, 'Work OpenAI');
        $active->chooseModel('gpt-4o-mini', new \DateTimeImmutable('2026-08-09T09:05:00Z'), null);
        $user->setActiveAiProviderSettings($active);
        $other = $this->configuration($user, 'Personal OpenRouter');
        $other->chooseModel('claude-3-haiku', new \DateTimeImmutable('2026-08-09T09:06:00Z'), null);

        $profile = MeJson::profile($user, true, 'UTC');

        self::assertIsArray($profile['ai']);
        self::assertTrue($profile['ai']['ready']);
        self::assertSame('gpt-4o-mini', $profile['ai']['model']);
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
