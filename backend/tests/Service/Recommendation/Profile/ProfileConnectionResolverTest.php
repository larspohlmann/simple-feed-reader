<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Profile\ProfileConnectionResolver;
use App\Tests\DbTestCase;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class ProfileConnectionResolverTest extends DbTestCase
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
        $this->owner = $this->user('profile-resolver@example.test');
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'jev-latest');
    }

    public function testTheChosenReadyLlmConnectionBuildsTheProfile(): void
    {
        $profile = $this->fixtures->seedProfileConnectionFor($this->owner);

        self::assertSame($profile, $this->resolver()->findUsableFor($this->owner));
    }

    /** A ready LLM connection the account never chose is not the profile connection: its history stays home. */
    public function testAnAccountThatChoseNoneHasNoneEvenWithAReadyLlmConnection(): void
    {
        $unchosen = $this->user('profile-resolver-unchosen@example.test');
        $this->fixtures->seedReadyAiSettingsFor($unchosen, 'gpt-4o');

        self::assertNull($this->resolver()->findUsableFor($unchosen));
    }

    /** A connection whose model later became a Jev model cannot distil: it reads as no profile connection. */
    public function testAChosenConnectionOnAJevModelIsNotUsable(): void
    {
        $this->fixtures->seedProfileConnectionFor($this->owner, 'jev-latest');

        self::assertNull($this->resolver()->findUsableFor($this->owner));
    }

    public function testAChosenConnectionWithoutAModelIsNotUsable(): void
    {
        $connection = AiProviderSettingsFactory::build($this->owner, 'No model', 'https://none.example.test/v1');
        $connection->setProfileSource(true);
        $this->entityManager->persist($connection);
        $this->entityManager->flush();

        self::assertNull($this->resolver()->findUsableFor($this->owner));
    }

    public function testADeletedChoiceLeavesNoneBesideAnUnchosenLlmConnection(): void
    {
        $profile = $this->fixtures->seedProfileConnectionFor($this->owner);
        $this->fixtures->seedProfileConnectionFor($this->owner, 'gpt-4o')->setProfileSource(false);
        $this->entityManager->remove($profile);
        $this->entityManager->flush();

        self::assertNull($this->resolver()->findUsableFor($this->owner));
    }

    /** Two flags only after a racing double choice; the newest one wins until the next choice repairs it. */
    public function testOfTwoChosenConnectionsTheNewestBuildsTheProfile(): void
    {
        $this->fixtures->seedProfileConnectionFor($this->owner);
        $newest = $this->fixtures->seedProfileConnectionFor($this->owner, 'gpt-4o');

        self::assertSame($newest, $this->resolver()->findUsableFor($this->owner));
    }

    public function testAnotherAccountsChoiceIsNotThisOnes(): void
    {
        $stranger = $this->user('profile-resolver-stranger@example.test');
        $this->fixtures->seedProfileConnectionFor($stranger);

        self::assertNull($this->resolver()->findUsableFor($this->owner));
    }

    public function testAJevConnectionBorrowsTheProfileConnection(): void
    {
        $profile = $this->fixtures->seedProfileConnectionFor($this->owner);
        $active = $this->owner->getActiveAiProviderSettings();
        self::assertNotNull($active);

        self::assertSame($profile, $this->resolver()->borrowedFor($active));
    }

    /** The LLM distils on its own connection, whatever the account chose. */
    public function testAnLlmConnectionBorrowsNothing(): void
    {
        $profile = $this->fixtures->seedProfileConnectionFor($this->owner);

        self::assertNull($this->resolver()->borrowedFor($profile));
    }

    private function resolver(): ProfileConnectionResolver
    {
        /** @var ProfileConnectionResolver $resolver */
        $resolver = self::getContainer()->get(ProfileConnectionResolver::class);

        return $resolver;
    }
}
