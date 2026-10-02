<?php

declare(strict_types=1);

namespace App\Service\Ai\Model;

final readonly class ProviderCallReceiptModel
{
    public function __construct(
        public ?string $requestId,
        public ?string $answeringModel,
        public ?ProviderCallUsageModel $usage,
    ) {
    }
}
