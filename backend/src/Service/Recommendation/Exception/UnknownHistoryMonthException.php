<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Exception;

/**
 * A month string that names no month. The history route's own requirement
 * rejects these before a controller sees them, so reaching this is a caller
 * mistake rather than a user one — but the value object states its contract
 * rather than trusting the route to be the only way in.
 */
final class UnknownHistoryMonthException extends \RuntimeException
{
}
