<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ai\Model\ModelDescriptorModel;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\ModelCatalog\ModelCatalogInterface;

/**
 * A model catalog answering from a script: a closure over the credentials, because with reboots disabled the
 * configurator keeps this instance for the whole case, so one instance must answer different keys differently.
 */
final readonly class StubModelCatalog implements ModelCatalogInterface
{
    /** @var \Closure(ProviderCredentialsModel): list<string|ModelDescriptorModel> */
    private \Closure $answer;

    /**
     * @param list<string|ModelDescriptorModel>|\Throwable
     *     |\Closure(ProviderCredentialsModel): list<string|ModelDescriptorModel> $answer
     */
    public function __construct(array|\Throwable|\Closure $answer)
    {
        $this->answer = match (true) {
            $answer instanceof \Closure => $answer,
            $answer instanceof \Throwable => static fn (): array => throw $answer,
            default => static fn (): array => $answer,
        };
    }

    public function listModels(ProviderCredentialsModel $credentials): array
    {
        return array_map(
            static fn (string|ModelDescriptorModel $entry): ModelDescriptorModel
                => $entry instanceof ModelDescriptorModel
                    ? $entry
                    : new ModelDescriptorModel($entry, null),
            ($this->answer)($credentials),
        );
    }
}
