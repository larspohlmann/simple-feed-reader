<?php

declare(strict_types=1);

namespace App\Tests\Service\Sourcing\Discovery;

use App\Entity\ReadingSettings;
use App\Entity\User;
use App\Repository\ReadingSettingsRepository;
use App\Service\Sourcing\Discovery\Exception\ScrapingDisabledException;
use App\Service\Sourcing\Discovery\Model\ScrapeFallback;
use App\Service\Sourcing\Discovery\ScrapeFallbackPolicy;
use App\Tests\DbTestCase;
use App\Tests\Support\StoredUserSettings;

final class ScrapeFallbackPolicyTest extends DbTestCase
{
    public function testForUserMapsTheDefaultOffPreferenceToDisabled(): void
    {
        self::assertSame(ScrapeFallback::Disabled, $this->policy()->forUser($this->user()));
    }

    public function testForUserMapsAnEnabledPreferenceToEnabled(): void
    {
        self::assertSame(ScrapeFallback::Enabled, $this->policy()->forUser($this->userWithScrapingEnabled()));
    }

    public function testAssertMayScrapeThrowsWhenThePreferenceIsOff(): void
    {
        $this->expectException(ScrapingDisabledException::class);

        $this->policy()->assertMayScrape($this->user());
    }

    public function testAssertMayScrapeAllowsWhenThePreferenceIsOn(): void
    {
        $this->policy()->assertMayScrape($this->userWithScrapingEnabled());

        // Reaching here without an exception IS the assertion; nothing more to check.
        $this->addToAssertionCount(1);
    }

    private function policy(): ScrapeFallbackPolicy
    {
        $readingSettings = $this->entityManager->getRepository(ReadingSettings::class);
        self::assertInstanceOf(ReadingSettingsRepository::class, $readingSettings);

        return new ScrapeFallbackPolicy($readingSettings);
    }

    private function user(): User
    {
        $user = new User('scrape-policy@example.com', new \DateTimeImmutable('2026-08-02 10:00:00'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function userWithScrapingEnabled(): User
    {
        $user = $this->user();
        StoredUserSettings::reading($this->entityManager, $user)->setScrapeFallbackEnabled(true);
        $this->entityManager->flush();

        return $user;
    }
}
