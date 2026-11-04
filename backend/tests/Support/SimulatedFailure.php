<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Thrown by a test double to prove a failure surfaces; no production code throws or catches it. */
final class SimulatedFailure extends \RuntimeException
{
}
