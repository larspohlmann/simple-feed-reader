<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\ModelCatalog;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ModelDescriptorModel;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\ModelCatalog\CompositeModelCatalog;
use App\Tests\Support\StubModelCatalog;
use PHPUnit\Framework\TestCase;

final class CompositeModelCatalogTest extends TestCase
{
    /** OpenRouter: its `/models` and the System One probe both answer; the first member wins a shared id. */
    public function testItUnitesTheMembersListsSortedAndTheFirstMemberWinsADuplicate(): void
    {
        $catalog = new CompositeModelCatalog([
            new StubModelCatalog([
                new ModelDescriptorModel('qwen/qwen3.7', 131_072),
                new ModelDescriptorModel('jev-latest', 1_000),
            ]),
            new StubModelCatalog([
                new ModelDescriptorModel('jev-latest', 32_000),
                new ModelDescriptorModel('anthropic/claude-sonnet', 200_000),
            ]),
        ]);

        self::assertEquals(
            [
                new ModelDescriptorModel('anthropic/claude-sonnet', 200_000),
                new ModelDescriptorModel('jev-latest', 1_000),
                new ModelDescriptorModel('qwen/qwen3.7', 131_072),
            ],
            $catalog->listModels($this->credentials()),
        );
    }

    /** TypeSafe direct: its `/models` is not OpenAI-shaped, the probe still finds the endpoint. */
    public function testAMembersFailureIsIgnoredWhenAnotherMemberRecognisesTheProvider(): void
    {
        $catalog = new CompositeModelCatalog([
            new StubModelCatalog(new ProviderUnreachableException('That address answered, but not with a model list.')),
            new StubModelCatalog(['jev-latest']),
        ]);

        self::assertSame(
            ['jev-latest'],
            array_map(
                static fn (ModelDescriptorModel $model): string => $model->id,
                $catalog->listModels($this->credentials()),
            ),
        );
    }

    public function testAMembersRefusedKeyIsIgnoredWhenAnotherMemberListsModels(): void
    {
        $catalog = new CompositeModelCatalog([
            new StubModelCatalog(new CredentialsRejectedException('That provider refused the API key.')),
            new StubModelCatalog(['jev-latest']),
        ]);

        self::assertCount(1, $catalog->listModels($this->credentials()));
    }

    /** A bad key on TypeSafe direct: both members fail, and the first member's verdict is the answer. */
    public function testWhenNoMemberRecognisesTheProviderTheFirstMembersFailureIsTheAnswer(): void
    {
        $catalog = new CompositeModelCatalog([
            new StubModelCatalog(new CredentialsRejectedException('That provider refused the API key.')),
            new StubModelCatalog(new ProviderUnreachableException('That address offers no System One endpoint.')),
        ]);

        $this->expectException(CredentialsRejectedException::class);
        $this->expectExceptionMessage('That provider refused the API key.');

        $catalog->listModels($this->credentials());
    }

    public function testWithoutMembersThereAreNoModels(): void
    {
        $this->expectExceptionMessage('That provider offers no models.');

        (new CompositeModelCatalog([]))->listModels($this->credentials());
    }

    private function credentials(): ProviderCredentialsModel
    {
        return ProviderCredentialsModel::fromStoredConfiguration('https://api.example.test/v1', 'sk-test');
    }
}
