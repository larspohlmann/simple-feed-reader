<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Profile\ProfileSettingsProvider;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class ProfileSettingsProviderTest extends DbTestCase
{
    use SeedsUsers;

    public function testTheSectionShowsTheStoredProfileAndTheDebugSwitch(): void
    {
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $owner = $this->user('profile-settings-provider@example.test');
        $fixtures->debugEnabledSettings($owner);
        $fixtures->storeProfile($owner, 'Likes maps.');

        $settings = $this->provider()->forUser($owner);

        self::assertSame('Likes maps.', $settings->storedProfile->getText());
        self::assertTrue($settings->debugEnabled);
    }

    public function testAnAccountWithoutSettingsHasNoProfileAndNoDebugLog(): void
    {
        $settings = $this->provider()->forUser($this->user('profile-settings-provider-empty@example.test'));

        self::assertNull($settings->storedProfile->getText());
        self::assertFalse($settings->debugEnabled);
    }

    private function provider(): ProfileSettingsProvider
    {
        /** @var ProfileSettingsProvider $provider */
        $provider = self::getContainer()->get(ProfileSettingsProvider::class);

        return $provider;
    }
}
