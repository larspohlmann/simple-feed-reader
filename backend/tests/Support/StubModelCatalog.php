<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ModelDescriptor;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\ModelCatalog\ModelCatalogInterface;

/**
 * A model catalog answering from a script: a closure over the credentials, because with reboots disabled the
 * configurator keeps this instance for the whole case, so one instance must answer different keys differently.
 */
final readonly class StubModelCatalog implements ModelCatalogInterface
{
    /** @var \Closure(ProviderCredentialsModel): list<string|ModelDescriptor> */
    private \Closure $answer;

    /**
     * @param list<string|ModelDescriptor>|\Throwable
     *     |\Closure(ProviderCredentialsModel): list<string|ModelDescriptor> $answer
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
            static fn (string|ModelDescriptor $entry): ModelDescriptor
                => $entry instanceof ModelDescriptor
                    ? $entry
                    : new ModelDescriptor($entry, null),
            ($this->answer)($credentials),
        );
    }
}
