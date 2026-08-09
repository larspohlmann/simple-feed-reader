<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Http\MeJson;
use App\Service\Ai\Crypto\SealedApiKey;
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
        return new AiProviderSettings(
            $user,
            $name,
            'https://api.example.test/v1',
            new SealedApiKey('c', 'n', 's', 1),
            'ab12',
            new \DateTimeImmutable('2026-08-09T09:00:00Z'),
        );
    }

    public function testAnAccountWithNoActiveConfigurationIsNotReady(): void
    {
        $profile = MeJson::profile($this->user());

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

        $profile = MeJson::profile($user);

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

        $profile = MeJson::profile($user);

        self::assertIsArray($profile['ai']);
        self::assertTrue($profile['ai']['ready']);
        self::assertSame('gpt-4o-mini', $profile['ai']['model']);
    }
}
