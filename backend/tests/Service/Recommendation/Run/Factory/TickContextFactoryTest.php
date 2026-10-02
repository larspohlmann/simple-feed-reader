<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\Factory;

use App\Enum\RecommendationEngineKind;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Recommendation\Run\Factory\TickContextFactory;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Tests\DbTestCase;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class TickContextFactoryTest extends DbTestCase
{
    use SeedsUsers;

    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
    }

    /** Worker, not the advancer's Poll default: the driver must come from the caller. */
    public function testTheTickCarriesTheRunTheActiveConnectionItsKindAndTheDriver(): void
    {
        $owner = $this->user('tick-context-factory@example.test');
        $this->fixtures->seedReadyAiSettings($owner);
        $run = $this->fixtures->createRun($owner);
        $this->entityManager->flush();

        $tick = $this->factory()->create($run, TickDriver::Worker);

        self::assertSame($run, $tick->run);
        self::assertSame($owner->getActiveAiProviderSettings(), $tick->connection);
        self::assertSame(RecommendationEngineKind::Llm, $tick->engineKind);
        self::assertSame(TickDriver::Worker, $tick->driver);
    }

    public function testAJevConnectionTicksWithTheJevKind(): void
    {
        $owner = $this->user('tick-context-jev@example.test');
        $this->fixtures->seedReadyAiSettingsFor($owner, 'jev-latest');
        $run = $this->fixtures->createRun($owner);
        $this->entityManager->flush();

        self::assertSame(RecommendationEngineKind::Jev, $this->factory()->create($run, TickDriver::Poll)->engineKind);
    }

    public function testAConnectionWithoutAModelCannotTick(): void
    {
        $owner = $this->user('tick-context-no-model@example.test');
        $connection = AiProviderSettingsFactory::build($owner);
        $this->entityManager->persist($connection);
        $owner->setActiveAiProviderSettings($connection);
        $run = $this->fixtures->createRun($owner);
        $this->entityManager->flush();

        $this->expectException(AiNotConfiguredException::class);
        $this->expectExceptionMessage('No model is chosen.');

        $this->factory()->create($run, TickDriver::Poll);
    }

    public function testAJevTickBorrowsTheProfileConnectionWithItsOwnSettings(): void
    {
        $owner = $this->user('tick-context-profile@example.test');
        $this->fixtures->seedReadyAiSettingsFor($owner, 'jev-latest');
        $profile = $this->fixtures->seedProfileConnectionFor($owner);
        $profile->setMaxBatchSize(30);
        $run = $this->fixtures->createRun($owner);
        $this->entityManager->flush();

        $tick = $this->factory()->create($run, TickDriver::Worker);

        self::assertNotNull($tick->profileTick);
        self::assertSame($profile, $tick->profileTick->connection);
        self::assertSame(RecommendationEngineKind::Llm, $tick->profileTick->engineKind);
        self::assertSame(30, $tick->profileTick->settings->packing->maximumBatchSize);
        self::assertSame(TickDriver::Worker, $tick->profileTick->driver);
        self::assertSame($run, $tick->profileTick->run);
    }

    public function testAJevTickWithoutAUsableProfileConnectionBorrowsNothing(): void
    {
        $owner = $this->user('tick-context-no-profile@example.test');
        $this->fixtures->seedReadyAiSettingsFor($owner, 'jev-latest');
        $run = $this->fixtures->createRun($owner);
        $this->entityManager->flush();

        self::assertNull($this->factory()->create($run, TickDriver::Poll)->profileTick);
    }

    /** The LLM distils on its own connection: a chosen profile connection is ignored. */
    public function testAnLlmTickBorrowsNothing(): void
    {
        $owner = $this->user('tick-context-llm-profile@example.test');
        $this->fixtures->seedReadyAiSettings($owner);
        $this->fixtures->seedProfileConnectionFor($owner);
        $run = $this->fixtures->createRun($owner);
        $this->entityManager->flush();

        self::assertNull($this->factory()->create($run, TickDriver::Poll)->profileTick);
    }

    private function factory(): TickContextFactory
    {
        /** @var TickContextFactory $factory */
        $factory = self::getContainer()->get(TickContextFactory::class);

        return $factory;
    }
}
