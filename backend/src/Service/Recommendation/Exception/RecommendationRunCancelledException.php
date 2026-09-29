<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Exception;

/**
 * Control flow, not a fault: the run was stopped during this tick's provider call. The call stays recorded as a
 * success; the tick unwinds without writing its result.
 */
final class RecommendationRunCancelledException extends \RuntimeException
{
}
