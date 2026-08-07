<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * One model as the provider's /models endpoint describes it. The context
 * window is null when the provider does not report one — most OpenAI-style
 * gateways do (context_length or max_context_length), OpenAI itself does not.
 */
final readonly class ModelDescriptor
{
    public function __construct(
        public string $id,
        public ?int $contextWindow,
    ) {
    }
}
