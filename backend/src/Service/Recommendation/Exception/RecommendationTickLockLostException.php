<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Exception;

/**
 * Another process holds this tick's per-user lock now (#444).
 *
 * Control flow, not a fault: nothing failed, and the provider call this tick
 * paid for succeeded. What this signals is that the run has a second tick
 * working on it, so writing what this one computed would double-bank the
 * winners the lock exists to serialise. The tick unwinds at its next
 * checkpoint and leaves the run to the process that owns it.
 */
final class RecommendationTickLockLostException extends \RuntimeException
{
}
