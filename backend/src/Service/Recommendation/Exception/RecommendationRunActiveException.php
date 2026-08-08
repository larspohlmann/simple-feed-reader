<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Exception;

/** The account's latest run is still pending or running: nothing to purge yet, only a run to wait out. */
final class RecommendationRunActiveException extends \RuntimeException
{
}
