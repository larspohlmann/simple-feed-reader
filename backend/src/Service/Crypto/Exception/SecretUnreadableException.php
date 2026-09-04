<?php

declare(strict_types=1);

namespace App\Service\Crypto\Exception;

/**
 * The stored material did not open. Three causes, deliberately not
 * distinguished: a wrong master secret, a row edited in the database, and a
 * row bound to another owner all mean the same thing to a caller — this secret
 * is gone, ask for it again. Telling them apart would only help someone
 * probing the store.
 */
final class SecretUnreadableException extends \RuntimeException
{
}
