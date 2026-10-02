<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Exception\ProfileConnectionRejectedException;
use App\Service\Recommendation\Profile\ProfileConnectionChooser;
use App\Tests\DbTestCase;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class ProfileConnectionChooserTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('profile-chooser@example.test');
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'gpt-4o');
    }

    public function testChoosingAConnectionMakesItTheOnlyProfileSource(): void
    {
        $first = $this->fixtures->seedProfileConnectionFor($this->owner);
        $second = $this->owner->getActiveAiProviderSettings();
        self::assertNotNull($second);

        $this->chooser()->choose($second);

        $this->entityManager->refresh($first);
        $this->entityManager->refresh($second);
        self::assertTrue($second->isProfileSource());
        self::assertFalse($first->isProfileSource());
    }

    public function testAJevConnectionIsRefused(): void
    {
        $jev = $this->fixtures->seedProfileConnectionFor($this->owner, 'jev-latest');
        $jev->setProfileSource(false);
        $this->entityManager->flush();

        try {
            $this->chooser()->choose($jev);
            self::fail('A Jev connection cannot build the profile.');
        } catch (ProfileConnectionRejectedException $exception) {
            self::assertSame(ProfileConnectionChooser::REJECTION, $exception->getMessage());
        }
        $this->entityManager->refresh($jev);
        self::assertFalse($jev->isProfileSource());
    }

    public function testAConnectionWithoutAModelIsRefused(): void
    {
        $connection = AiProviderSettingsFactory::build($this->owner, 'No model', 'https://none.example.test/v1');
        $this->entityManager->persist($connection);
        $this->entityManager->flush();

        $this->expectException(ProfileConnectionRejectedException::class);

        $this->chooser()->choose($connection);
    }

    /** Clearing leaves the account without a profile connection; clearing again changes nothing. */
    public function testClearingUnsetsTheChoiceAndIsIdempotent(): void
    {
        $profile = $this->fixtures->seedProfileConnectionFor($this->owner);

        $this->chooser()->clear($profile);
        $this->chooser()->clear($profile);

        $this->entityManager->refresh($profile);
        self::assertFalse($profile->isProfileSource());
    }

    private function chooser(): ProfileConnectionChooser
    {
        /** @var ProfileConnectionChooser $chooser */
        $chooser = self::getContainer()->get(ProfileConnectionChooser::class);

        return $chooser;
    }
}
