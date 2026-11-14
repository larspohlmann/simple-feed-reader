<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

/** The proxied attempt failed in a way a pinned direct route may still serve; never escapes FailoverRequestSender. */
final class ProxiedAttemptFailedException extends \RuntimeException
{
}
