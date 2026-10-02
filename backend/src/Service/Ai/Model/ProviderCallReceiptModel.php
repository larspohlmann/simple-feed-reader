<?php

declare(strict_types=1);

namespace App\Service\Ai\Model;

/** What a provider said about one answered call besides the answer: its id, the model version that answered, usage. */
final readonly class ProviderCallReceiptModel
{
    public function __construct(
        public ?string $requestId,
        public ?string $answeringModel,
        public ?ProviderCallUsageModel $usage,
    ) {
    }
}
