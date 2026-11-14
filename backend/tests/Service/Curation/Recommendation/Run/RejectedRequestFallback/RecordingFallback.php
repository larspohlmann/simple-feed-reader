<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\RejectedRequestFallback;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Run\Model\RequestAdjustmentModel;
use App\Service\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface;

final readonly class RecordingFallback implements RejectedRequestFallbackInterface
{
    /** @param \ArrayObject<int, string> $askedInOrder */
    public function __construct(
        private string $name,
        private ?RequestAdjustmentModel $adjustment,
        private \ArrayObject $askedInOrder,
    ) {
    }

    public function adjust(
        AiProviderSettings $connection,
        ProviderRejectedRequestException $rejection,
    ): ?RequestAdjustmentModel {
        $this->askedInOrder->append($this->name);

        return $this->adjustment;
    }
}
