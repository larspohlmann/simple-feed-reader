<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\RejectedRequestFallback;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Exception\ProviderRejectedRequestException;

/** A rejection the next tick can answer differently: absorbing one records why on the connection, and the run goes on. */
interface RejectedRequestFallbackInterface
{
    public function absorbs(AiProviderSettings $connection, ProviderRejectedRequestException $rejection): bool;
}
