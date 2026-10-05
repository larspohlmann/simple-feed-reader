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

    public function testAScoringConnectionTicksWithTheScoringKind(): void
    {
        $owner = $this->user('tick-context-jev@example.test');
        $this->fixtures->seedReadyScoringSettings($owner);
        $run = $this->fixtures->createRun($owner);
        $this->entityManager->flush();

        $context = $this->factory()->create($run, TickDriver::Poll);

        self::assertSame(RecommendationEngineKind::Scoring, $context->engineKind);
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

    private function factory(): TickContextFactory
    {
        /** @var TickContextFactory $factory */
        $factory = self::getContainer()->get(TickContextFactory::class);

        return $factory;
    }
}
