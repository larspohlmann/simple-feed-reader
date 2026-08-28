<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Http\MeJson;
use App\Service\Mail\Digest\DigestCadence;
use App\Tests\Support\AiProviderSettingsFactory;
use PHPUnit\Framework\TestCase;

/**
 * MeJson's `ai` block is the one part of the profile that reads an
 * association rather than a plain column, and #334 changed what it reads
 * from (the account's single provider row, to whichever of its many
 * configurations is active) — so this pins that it reflects the ACTIVE
 * configuration specifically, not just "the account has one".
 */
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
        $profile = MeJson::profile($this->user(), true);

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

        $profile = MeJson::profile($user, true);

        self::assertIsArray($profile['ai']);
        self::assertTrue($profile['ai']['ready']);
        self::assertSame('gpt-4o-mini', $profile['ai']['model']);
    }

    /**
     * A second configuration existing — even a fully verified one, complete
     * with its own chosen model — must not change what /api/me reports as
     * long as it never became the active one. Without this, a bug that read
     * "any configuration" instead of "the active one" would still pass the
     * single-configuration case above.
     */
    public function testASecondNonActiveConfigurationDoesNotChangeTheReportedState(): void
    {
        $user = $this->user();
        $active = $this->configuration($user, 'Work OpenAI');
        $active->chooseModel('gpt-4o-mini', new \DateTimeImmutable('2026-08-09T09:05:00Z'), null);
        $user->setActiveAiProviderSettings($active);
        $other = $this->configuration($user, 'Personal OpenRouter');
        $other->chooseModel('claude-3-haiku', new \DateTimeImmutable('2026-08-09T09:06:00Z'), null);

        $profile = MeJson::profile($user, true);

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

        $profile = MeJson::profile($user, true);

        self::assertSame(['enabled' => true], $profile['mail']);
        self::assertTrue($profile['emailVerified']);
        self::assertIsArray($profile['preferences']);
        self::assertSame(
            ['enabled' => true, 'cadence' => 'weekly', 'sendHour' => 9, 'weekday' => 3],
            $profile['preferences']['digest'],
        );
    }
}
