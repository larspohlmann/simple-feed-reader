<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\StoryAi;

use App\Entity\AiProviderSettings;
use App\Entity\StorySettings;
use App\Entity\User;
use App\Service\Curation\Ai\Crypto\ApiKeyCipher;
use App\Service\Curation\StoryAi\StoryWritingConnections;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class StoryWritingConnectionsTest extends DbTestCase
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
        $this->owner = $this->user('story-connections@example.test');
    }

    public function testNoRowMeansTheActiveChatConnection(): void
    {
        $active = $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');

        self::assertSame($active, $this->connections()->findUsableConnection($this->owner));
    }

    public function testAChosenChatConnectionWinsOverTheActiveOne(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
        $chosen = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');
        $this->storeSettings($chosen, true);

        self::assertSame($chosen, $this->connections()->findUsableConnection($this->owner));
    }

    public function testAChosenScoringConnectionGivesNoneRatherThanTheActiveOne(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
        $this->storeSettings($this->fixtures->seedInactiveScoringSettings($this->owner), true);

        self::assertNull($this->connections()->findUsableConnection($this->owner));
    }

    public function testAScoringConnectionCannotWrite(): void
    {
        $this->fixtures->seedReadyScoringSettings($this->owner);
        $chat = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');

        self::assertNull($this->connections()->findUsableConnection($this->owner));
        self::assertSame([$chat->getId()], $this->candidateIds());
    }

    public function testTitlesSwitchedOffStillResolveTheConnection(): void
    {
        $active = $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
        $this->storeSettings(null, false);

        self::assertSame($active, $this->connections()->findUsableConnection($this->owner));
    }

    /** @return list<?int> */
    private function candidateIds(): array
    {
        return array_map(
            static fn (AiProviderSettings $connection): ?int => $connection->getId(),
            $this->connections()->findCandidates($this->owner),
        );
    }

    private function storeSettings(?AiProviderSettings $writingConnection, bool $titlesEnabled): void
    {
        $settings = new StorySettings($this->owner);
        $settings->update($writingConnection, $titlesEnabled);
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
    }

    private function connections(): StoryWritingConnections
    {
        $connections = self::getContainer()->get(StoryWritingConnections::class);
        self::assertInstanceOf(StoryWritingConnections::class, $connections);

        return $connections;
    }
}
