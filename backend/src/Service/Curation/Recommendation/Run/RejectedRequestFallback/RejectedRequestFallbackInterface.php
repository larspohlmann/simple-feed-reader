<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Run\RejectedRequestFallback;

use App\Entity\AiProviderSettings;
use App\Service\Curation\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Curation\Recommendation\Run\Model\RequestAdjustmentModel;

/** A rejection the next tick can answer differently: adjusting records the change on the connection; the run goes on. */
interface RejectedRequestFallbackInterface
{
    /** Null when this fallback has no answer to the rejection. */
    public function adjust(
        AiProviderSettings $connection,
        ProviderRejectedRequestException $rejection,
    ): ?RequestAdjustmentModel;
}
