<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Exception\ProfileConnectionRejectedException;
use App\Service\Recommendation\Exception\ProfileNotBorrowedException;
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
    private AiProviderSettings $jev;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('profile-chooser@example.test');
        $this->jev = $this->fixtures->seedReadyAiSettingsFor($this->owner, 'jev-latest');
    }

    public function testChoosingPointsTheJevConnectionAtTheConnection(): void
    {
        $connection = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');

        $this->chooser()->choose($this->jev, $connection);

        $this->entityManager->refresh($this->jev);
        self::assertSame($connection, $this->jev->getProfileConnection());
    }

    public function testChoosingAgainMovesThePointer(): void
    {
        $this->fixtures->seedProfileConnectionFor($this->owner);
        $next = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');

        $this->chooser()->choose($this->jev, $next);

        $this->entityManager->refresh($this->jev);
        self::assertSame($next, $this->jev->getProfileConnection());
    }

    public function testEachJevConnectionKeepsItsOwnChoice(): void
    {
        $other = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'jev-latest');
        $first = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');
        $second = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o-mini');

        $this->chooser()->choose($this->jev, $first);
        $this->chooser()->choose($other, $second);

        $this->entityManager->refresh($this->jev);
        $this->entityManager->refresh($other);
        self::assertSame($first, $this->jev->getProfileConnection());
        self::assertSame($second, $other->getProfileConnection());
    }

    public function testAJevConnectionIsRefusedAsTheProfileConnection(): void
    {
        $other = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'jev-latest');

        try {
            $this->chooser()->choose($this->jev, $other);
            self::fail('A Jev connection cannot build the profile.');
        } catch (ProfileConnectionRejectedException $exception) {
            self::assertSame(ProfileConnectionChooser::REJECTION, $exception->getMessage());
        }
        $this->entityManager->refresh($this->jev);
        self::assertNull($this->jev->getProfileConnection());
    }

    public function testAConnectionWithoutAModelIsRefused(): void
    {
        $connection = AiProviderSettingsFactory::build($this->owner, 'No model', 'https://none.example.test/v1');
        $this->entityManager->persist($connection);
        $this->entityManager->flush();

        $this->expectException(ProfileConnectionRejectedException::class);

        $this->chooser()->choose($this->jev, $connection);
    }

    public function testAConnectionThatBuildsItsOwnProfileBorrowsNone(): void
    {
        $llm = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');
        $connection = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o-mini');

        try {
            $this->chooser()->choose($llm, $connection);
            self::fail('An LLM connection builds its own profile.');
        } catch (ProfileNotBorrowedException $exception) {
            self::assertSame(ProfileConnectionChooser::NOT_BORROWING, $exception->getMessage());
        }
        $this->entityManager->refresh($llm);
        self::assertNull($llm->getProfileConnection());
    }

    public function testClearingUnsetsTheChoiceAndIsIdempotent(): void
    {
        $this->fixtures->seedProfileConnectionFor($this->owner);

        $this->chooser()->clear($this->jev);
        $this->chooser()->clear($this->jev);

        $this->entityManager->refresh($this->jev);
        self::assertNull($this->jev->getProfileConnection());
    }

    private function chooser(): ProfileConnectionChooser
    {
        /** @var ProfileConnectionChooser $chooser */
        $chooser = self::getContainer()->get(ProfileConnectionChooser::class);

        return $chooser;
    }
}
