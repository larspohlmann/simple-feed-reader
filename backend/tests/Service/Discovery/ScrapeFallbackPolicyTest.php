<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Entity\User;
use App\Enum\ScrapeFallback;
use App\Service\Discovery\Exception\ScrapingDisabledException;
use App\Service\Discovery\ScrapeFallbackPolicy;
use PHPUnit\Framework\TestCase;

/**
 * No database needed: the policy only reads the in-memory Preferences the
 * User constructor already attaches, so a plain TestCase is enough.
 */
final class ScrapeFallbackPolicyTest extends TestCase
{
    private function user(): User
    {
        return new User('scrape-policy@example.com', new \DateTimeImmutable('2026-08-02 10:00:00'));
    }

    public function testForUserMapsTheDefaultOffPreferenceToDisabled(): void
    {
        self::assertSame(ScrapeFallback::Disabled, (new ScrapeFallbackPolicy())->forUser($this->user()));
    }

    public function testForUserMapsAnEnabledPreferenceToEnabled(): void
    {
        $user = $this->user();
        $user->getPreferences()->setScrapeFallbackEnabled(true);

        self::assertSame(ScrapeFallback::Enabled, (new ScrapeFallbackPolicy())->forUser($user));
    }

    public function testAssertMayScrapeThrowsWhenThePreferenceIsOff(): void
    {
        $this->expectException(ScrapingDisabledException::class);

        (new ScrapeFallbackPolicy())->assertMayScrape($this->user());
    }

    public function testAssertMayScrapeAllowsWhenThePreferenceIsOn(): void
    {
        $user = $this->user();
        $user->getPreferences()->setScrapeFallbackEnabled(true);

        (new ScrapeFallbackPolicy())->assertMayScrape($user);

        // Reaching here without an exception IS the assertion; nothing more to check.
        $this->addToAssertionCount(1);
    }
}
