<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\ModelCatalog;

use App\Entity\ModelDescriptor;
use App\Enum\ScoringProtocol;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\ModelCatalog\CompositeModelCatalog;
use App\Tests\Support\StubModelCatalog;
use PHPUnit\Framework\TestCase;

final class CompositeModelCatalogTest extends TestCase
{
    /** OpenRouter: its listing names decision models, so the probe is never asked and Jev is listed once. */
    public function testAListingThatNamesASystemOneModelIsNeverProbed(): void
    {
        $probes = new \ArrayObject();
        $catalog = new CompositeModelCatalog(
            [new StubModelCatalog([
                new ModelDescriptor('qwen/qwen3.7-flash', 1_000_000),
                new ModelDescriptor('~typesafe/jev-latest', 32_000, ScoringProtocol::SystemOne),
            ])],
            new StubModelCatalog(static function () use ($probes): array {
                $probes->append('probed');

                return [new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne)];
            }),
        );

        self::assertSame(
            ['qwen/qwen3.7-flash', '~typesafe/jev-latest'],
            self::ids($catalog->listModels(self::credentials())),
        );
        self::assertCount(0, $probes);
    }

    /** TypeSafe direct: its `/models` is not OpenAI-shaped, and the probe finds the endpoint. */
    public function testWithoutAListedSystemOneModelTheProbeAddsItsAlias(): void
    {
        $catalog = new CompositeModelCatalog(
            [new StubModelCatalog(
                new ProviderUnreachableException('That address answered, but not with a model list.'),
            )],
            new StubModelCatalog([new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne)]),
        );

        self::assertEquals(
            [new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne)],
            $catalog->listModels(self::credentials()),
        );
    }

    /** A gateway that lists the alias as a plain model: the probe proved the endpoint, so its tag is kept. */
    public function testTheProbesDescriptionWinsAnIdTheListingNamedUntagged(): void
    {
        $catalog = new CompositeModelCatalog(
            [new StubModelCatalog([
                new ModelDescriptor('gpt-4o', 128_000),
                new ModelDescriptor('jev-latest', null),
            ])],
            new StubModelCatalog([new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne)]),
        );

        self::assertEquals(
            [
                new ModelDescriptor('gpt-4o', 128_000),
                new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne),
            ],
            $catalog->listModels(self::credentials()),
        );
    }

    /** LM Studio: the listing answers, the probe finds no endpoint, and the LLMs are the answer. */
    public function testAProbeThatFindsNoEndpointLeavesTheListingAlone(): void
    {
        $catalog = new CompositeModelCatalog(
            [new StubModelCatalog(['qwen3-14b'])],
            self::noSystemOneEndpoint(),
        );

        self::assertSame(['qwen3-14b'], self::ids($catalog->listModels(self::credentials())));
    }

    public function testItUnitesTheMembersListsSortedAndTheFirstMemberWinsADuplicate(): void
    {
        $catalog = new CompositeModelCatalog(
            [
                new StubModelCatalog([new ModelDescriptor('qwen/qwen3.7', 131_072), new ModelDescriptor('gpt-4o', 1_000)]),
                new StubModelCatalog([
                    new ModelDescriptor('gpt-4o', 128_000),
                    new ModelDescriptor('anthropic/claude-sonnet', 200_000),
                ]),
            ],
            self::noSystemOneEndpoint(),
        );

        self::assertEquals(
            [
                new ModelDescriptor('anthropic/claude-sonnet', 200_000),
                new ModelDescriptor('gpt-4o', 1_000),
                new ModelDescriptor('qwen/qwen3.7', 131_072),
            ],
            $catalog->listModels(self::credentials()),
        );
    }

    public function testOneMembersRepeatedIdKeepsItsFirstDescriptionAndIdsSortAsStrings(): void
    {
        $catalog = new CompositeModelCatalog(
            [new StubModelCatalog([
                new ModelDescriptor('9', 1_000),
                new ModelDescriptor('10', 2_000),
                new ModelDescriptor('9', 3_000),
            ])],
            self::noSystemOneEndpoint(),
        );

        self::assertEquals(
            [new ModelDescriptor('10', 2_000), new ModelDescriptor('9', 1_000)],
            $catalog->listModels(self::credentials()),
        );
    }

    public function testAMembersRefusedKeyIsIgnoredWhenTheProbeListsItsAlias(): void
    {
        $catalog = new CompositeModelCatalog(
            [new StubModelCatalog(new CredentialsRejectedException('That provider refused the API key.'))],
            new StubModelCatalog([new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne)]),
        );

        self::assertCount(1, $catalog->listModels(self::credentials()));
    }

    /** A bad key on TypeSafe direct: the listing and the probe both fail, and the listing's verdict is the answer. */
    public function testWhenNothingRecognisesTheProviderTheFirstFailureIsTheAnswer(): void
    {
        $catalog = new CompositeModelCatalog(
            [new StubModelCatalog(new CredentialsRejectedException('That provider refused the API key.'))],
            self::noSystemOneEndpoint(),
        );

        $this->expectException(CredentialsRejectedException::class);
        $this->expectExceptionMessage('That provider refused the API key.');

        $catalog->listModels(self::credentials());
    }

    public function testWithoutMembersOrAProbeAnswerThereAreNoModels(): void
    {
        $this->expectExceptionMessage('That provider offers no models.');

        (new CompositeModelCatalog([], new StubModelCatalog([])))->listModels(self::credentials());
    }

    private static function noSystemOneEndpoint(): StubModelCatalog
    {
        return new StubModelCatalog(new ProviderUnreachableException('That address offers no System One endpoint.'));
    }

    /**
     * @param list<ModelDescriptor> $models
     *
     * @return list<string>
     */
    private static function ids(array $models): array
    {
        return array_map(static fn (ModelDescriptor $model): string => $model->id, $models);
    }

    private static function credentials(): ProviderCredentialsModel
    {
        return ProviderCredentialsModel::fromStoredConfiguration('https://api.example.test/v1', 'sk-test');
    }
}
