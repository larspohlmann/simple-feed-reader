<?php

declare(strict_types=1);

namespace App\Service\Identity\Auth\Exception;

/** A one-time link token that is invalid, used or expired. Not Lexik's JWT InvalidTokenException. */
final class InvalidTokenException extends \RuntimeException
{
}
