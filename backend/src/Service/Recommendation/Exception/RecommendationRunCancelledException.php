<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Exception;

/**
 * The run was stopped while this tick was inside its provider call.
 *
 * Control flow, not a fault: the call itself succeeded and is recorded as
 * such. What this signals is that the result now belongs to a run nobody is
 * waiting for, so the tick must unwind without writing it.
 */
final class RecommendationRunCancelledException extends \RuntimeException
{
}
