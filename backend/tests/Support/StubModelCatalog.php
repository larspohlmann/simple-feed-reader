<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ai\ModelCatalog;
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
 */
final readonly class StubModelCatalog implements ModelCatalog
{
    /** @var \Closure(ProviderCredentials): list<string> */
    private \Closure $answer;

    /** @param list<string>|\Throwable|\Closure(ProviderCredentials): list<string> $answer */
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
        return ($this->answer)($credentials);
    }
}
