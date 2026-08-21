<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\Entry;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\ConsolidationOutcome;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationConsolidationResolver;
use App\Service\Recommendation\RecommendationSettingsResolver;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Against the real ranker, candidate loader, history loader, prompt builder
 * and call recorder, not mocks -- mirrors RecommendationProfileDistillerTest's
 * rationale: resolve()'s job is to coordinate the pool cut, the prompt build,
 * the provider call and the ranked-list assembly, and a mock would have to
 * encode that coordination itself instead of proving it. The provider itself
 * is the one seam worth faking: StubChatClient stands in for it, registered
 * as the container's ChatCompletionClient in the test environment.
 */
final class RecommendationConsolidationResolverTest extends DbTestCase
{
    private User $user;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->em, $hasher))->create('consolidation-resolver@example.test');
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
        $this->fixtures->seedReadyAiSettings($this->user);
    }

    public function testUsableReplyDropsDuplicatesAndSortsByNewScore(): void
    {
        [$firstEntry, $secondEntry] = $this->fixtures->seedFeedWithEntries($this->user, 2);
        $firstId = $this->idOf($firstEntry);
        $secondId = $this->idOf($secondEntry);

        $run = $this->runWithWinners([
            ['id' => $firstId, 'score' => 400, 'reason' => ''],
            ['id' => $secondId, 'score' => 700, 'reason' => ''],
        ]);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [
                ['id' => $firstId, 'score' => 950, 'reason' => 'On Rust.'],
                ['id' => $secondId, 'score' => 300, 'reason' => 'Weak fit.'],
            ],
            'duplicates' => [$secondId],
        ], \JSON_THROW_ON_ERROR));

        $outcome = $this->resolveConsolidation($run);

        self::assertTrue($outcome->usable);
        self::assertSame([$firstId], array_map(static fn (array $pick): int => $pick['id'], $outcome->ranked));
        self::assertSame('On Rust.', $outcome->ranked[0]['reason']);
        self::assertSame(950, $outcome->ranked[0]['score']);
    }

    public function testUnusableReplyFallsBackToBatchScorePoolWithEmptyReasons(): void
    {
        [$firstEntry, $secondEntry] = $this->fixtures->seedFeedWithEntries($this->user, 2);
        $firstId = $this->idOf($firstEntry);
        $secondId = $this->idOf($secondEntry);

        $run = $this->runWithWinners([
            ['id' => $firstId, 'score' => 700, 'reason' => ''],
            ['id' => $secondId, 'score' => 400, 'reason' => ''],
        ]);

        $this->stubChatClient()->queueContent('not json');

        $outcome = $this->resolveConsolidation($run);

        self::assertFalse($outcome->usable);
        self::assertSame(
            [$firstId, $secondId],
            array_map(static fn (array $pick): int => $pick['id'], $outcome->requireFallbackPool()),
        );
        self::assertSame('not json', $outcome->requireUnusableReply());
        self::assertSame(['', ''], array_map(
            static fn (array $pick): string => $pick['reason'],
            $outcome->requireFallbackPool(),
        ));
    }

    public function testConsolidationSendsTheConsolidationSchema(): void
    {
        [$entry] = $this->fixtures->seedFeedWithEntries($this->user, 1);
        $id = $this->idOf($entry);

        $run = $this->runWithWinners([['id' => $id, 'score' => 500, 'reason' => '']]);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $id, 'score' => 600, 'reason' => 'Fits.']],
            'duplicates' => [],
        ], \JSON_THROW_ON_ERROR));

        $this->resolver()->resolve($run, $this->activeAiSettings(), $this->userId(), 50, $this->effectiveSettings());

        $calls = $this->stubChatClient()->calls();
        self::assertCount(1, $calls);
        self::assertSame('recommendations', $calls[0]['responseSchemaName']);
    }

    public function testAllWinnersPrunedFinalizesWithoutAProviderCall(): void
    {
        $entries = $this->fixtures->seedFeedWithEntries($this->user, 1);
        $id = $this->idOf($entries[0]);

        $run = $this->runWithWinners([['id' => $id, 'score' => 500, 'reason' => '']]);

        $entry = $this->em->getRepository(Entry::class)->find($id);
        self::assertNotNull($entry);
        $this->em->remove($entry);
        $this->em->flush();

        $outcome = $this->resolveConsolidation($run);

        self::assertTrue($outcome->usable);
        self::assertSame([], $outcome->ranked);
        self::assertCount(0, $this->stubChatClient()->calls());
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $winners
     */
    private function runWithWinners(array $winners): RecommendationRun
    {
        $run = $this->fixtures->createRun($this->user);
        $run->snapshot([array_column($winners, 'id')]);
        $run->recordBatchWinners($winners);
        $this->em->flush();

        return $run;
    }

    private function resolveConsolidation(RecommendationRun $run): ConsolidationOutcome
    {
        return $this->resolver()->resolve(
            $run,
            $this->activeAiSettings(),
            $this->userId(),
            50,
            $this->effectiveSettings(),
        );
    }

    private function idOf(Entry $entry): int
    {
        return $entry->getId() ?? throw new \LogicException('Entry was never saved.');
    }

    private function activeAiSettings(): AiProviderSettings
    {
        $settings = $this->user->getActiveAiProviderSettings();
        self::assertNotNull($settings);

        return $settings;
    }

    private function effectiveSettings(): EffectiveRecommendationSettings
    {
        /** @var RecommendationSettingsResolver $resolver */
        $resolver = self::getContainer()->get(RecommendationSettingsResolver::class);

        return $resolver->forUser($this->user);
    }

    private function userId(): int
    {
        return $this->user->getId() ?? throw new \LogicException('User was never saved.');
    }

    private function resolver(): RecommendationConsolidationResolver
    {
        /** @var RecommendationConsolidationResolver $resolver */
        $resolver = self::getContainer()->get(RecommendationConsolidationResolver::class);

        return $resolver;
    }

    private function stubChatClient(): StubChatClient
    {
        /** @var StubChatClient $client */
        $client = self::getContainer()->get(StubChatClient::class);

        return $client;
    }
}
