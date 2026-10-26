<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\RejectedRequestFallback;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface;

final readonly class RecordingFallback implements RejectedRequestFallbackInterface
{
    /** @param \ArrayObject<int, string> $askedInOrder */
    public function __construct(
        private string $name,
        private bool $verdict,
        private \ArrayObject $askedInOrder,
    ) {
    }

    public function absorbs(AiProviderSettings $connection, ProviderRejectedRequestException $rejection): bool
    {
        $this->askedInOrder->append($this->name);

        return $this->verdict;
    }
}
