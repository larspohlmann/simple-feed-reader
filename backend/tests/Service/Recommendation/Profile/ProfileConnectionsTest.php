<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Profile\ProfileConnections;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class ProfileConnectionsTest extends DbTestCase
{
    use SeedsUsers;

    private RecommendationRunFixtures $fixtures;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('profile-connections@example.test');
    }

    public function testWithNothingChosenTheActiveLlmConnectionBuildsTheProfile(): void
    {
        $active = $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');

        self::assertSame($active, $this->connections()->usableFor($this->owner));
    }

    public function testAChosenConnectionWinsOverTheActiveOne(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
        $chosen = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');
        $this->fixtures->chooseProfileConnection($this->owner, $chosen);

        self::assertSame($chosen, $this->connections()->usableFor($this->owner));
    }

    public function testAChosenConnectionThatCannotBuildAProfileGivesNoneRatherThanTheActiveOne(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
        $this->fixtures->chooseProfileConnection(
            $this->owner,
            $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'jev-latest'),
        );

        self::assertNull($this->connections()->usableFor($this->owner));
    }

    public function testAnActiveScoringConnectionWithNothingChosenGivesNone(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'jev-latest');

        self::assertNull($this->connections()->usableFor($this->owner));
    }

    public function testTheCandidatesAreTheConnectionsThatCanBuildAProfile(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'jev-latest');
        $llm = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');

        self::assertSame(
            [$llm->getId()],
            array_map(
                static fn (AiProviderSettings $connection): ?int => $connection->getId(),
                $this->connections()->candidatesFor($this->owner),
            ),
        );
    }

    private function connections(): ProfileConnections
    {
        /** @var ProfileConnections $connections */
        $connections = self::getContainer()->get(ProfileConnections::class);

        return $connections;
    }
}
