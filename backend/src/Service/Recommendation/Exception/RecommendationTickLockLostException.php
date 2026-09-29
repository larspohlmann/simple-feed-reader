<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Exception;

/**
 * Control flow, not a fault: another process holds this tick's per-user lock now, and writing this tick's result would
 * double-bank the winners. The tick unwinds at its next checkpoint and leaves the run to the lock's owner.
 */
final class RecommendationTickLockLostException extends \RuntimeException
{
}
