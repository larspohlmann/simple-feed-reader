<?php

declare(strict_types=1);

namespace App\Service\OAuth\Exception;

/** One class for every refusal (unknown, spent, expired, other browser, corrupt stored entry), so the callback cannot leak which. */
final class InvalidOAuthStateException extends \RuntimeException
{
}
