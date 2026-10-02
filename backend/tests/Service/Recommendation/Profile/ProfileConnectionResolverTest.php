<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
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
    private AiProviderSettings $jev;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('profile-resolver@example.test');
        $this->jev = $this->fixtures->seedReadyAiSettingsFor($this->owner, 'jev-latest');
    }

    public function testAJevConnectionBorrowsTheConnectionItPointsAt(): void
    {
        $profile = $this->fixtures->seedProfileConnectionFor($this->owner);

        self::assertSame($profile, $this->resolver()->borrowedFor($this->jev));
    }

    public function testEachJevConnectionBorrowsItsOwnChoice(): void
    {
        $first = $this->fixtures->seedProfileConnectionFor($this->owner);
        $other = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'jev-latest');
        $second = $this->fixtures->seedProfileConnectionBorrowedBy($other, 'gpt-4o');

        self::assertSame($first, $this->resolver()->borrowedFor($this->jev));
        self::assertSame($second, $this->resolver()->borrowedFor($other));
    }

    /** A connection whose model later became a Jev model cannot distil: it reads as no profile connection. */
    public function testAChosenConnectionOnAJevModelIsNotBorrowed(): void
    {
        $this->fixtures->seedProfileConnectionFor($this->owner, 'jev-latest');

        self::assertNull($this->resolver()->borrowedFor($this->jev));
    }

    public function testAChosenConnectionWithoutAModelIsNotBorrowed(): void
    {
        $connection = AiProviderSettingsFactory::build($this->owner, 'No model', 'https://none.example.test/v1');
        $this->entityManager->persist($connection);
        $this->jev->setProfileConnection($connection);
        $this->entityManager->flush();

        self::assertNull($this->resolver()->borrowedFor($this->jev));
    }

    /** The LLM distils on its own connection, whatever its row points at. */
    public function testAnLlmConnectionBorrowsNothing(): void
    {
        $llm = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');
        $this->fixtures->seedProfileConnectionBorrowedBy($llm);

        self::assertNull($this->resolver()->borrowedFor($llm));
    }

    public function testOnlyAConnectionWhoseEngineCannotDistilBorrows(): void
    {
        $llm = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');

        self::assertTrue($this->resolver()->borrows($this->jev));
        self::assertFalse($this->resolver()->borrows($llm));
    }

    private function resolver(): ProfileConnectionResolver
    {
        /** @var ProfileConnectionResolver $resolver */
        $resolver = self::getContainer()->get(ProfileConnectionResolver::class);

        return $resolver;
    }
}
