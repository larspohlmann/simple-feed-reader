<?php

declare(strict_types=1);

namespace App\Service\Ai\Crypto\Exception;

/**
 * The stored material did not open. Three causes, deliberately not
 * distinguished: a wrong master secret, a row edited in the database, and a
 * row moved to another account all mean the same thing to a caller — this key
 * is gone, ask the account to enter it again. Telling them apart would only
 * help someone probing the store.
 */
final class ApiKeyUnreadableException extends \RuntimeException
{
}
