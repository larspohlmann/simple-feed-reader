<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Exception;

/**
 * A month string that names no month. The history route rejects these first, so reaching this is a caller's mistake.
 */
final class UnknownHistoryMonthException extends \RuntimeException
{
}
