<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ai\ModelCatalog;
use App\Service\Ai\ModelDescriptor;
use App\Service\Ai\ProviderCredentials;

/**
 * A model catalog that answers from a script instead of from a network.
 *
 * The answer is a closure over the credentials rather than a value that a test
 * mutates between requests: KernelBrowser keeps one container for the whole
 * case once reboots are disabled, so a service already handed to the
 * configurator can no longer be replaced. A closure lets one instance answer
 * differently for different keys, which is what a case that saves a good
 * connection and then a refused one needs.
 *
 * A plain identifier is accepted alongside a full ModelDescriptor: most
 * callers only care which ids are offered, not their context windows, and
 * writing a bare string for those keeps the existing scripts unchanged.
 */
final readonly class StubModelCatalog implements ModelCatalog
{
    /** @var \Closure(ProviderCredentials): list<string|ModelDescriptor> */
    private \Closure $answer;

    /** @param list<string|ModelDescriptor>|\Throwable|\Closure(ProviderCredentials): list<string|ModelDescriptor> $answer */
    public function __construct(array|\Throwable|\Closure $answer)
    {
        $this->answer = match (true) {
            $answer instanceof \Closure => $answer,
            $answer instanceof \Throwable => static fn (): array => throw $answer,
            default => static fn (): array => $answer,
        };
    }

    public function listModels(ProviderCredentials $credentials): array
    {
        return array_map(
            static fn (string|ModelDescriptor $entry): ModelDescriptor => $entry instanceof ModelDescriptor
                ? $entry
                : new ModelDescriptor($entry, null),
            ($this->answer)($credentials),
        );
    }
}
