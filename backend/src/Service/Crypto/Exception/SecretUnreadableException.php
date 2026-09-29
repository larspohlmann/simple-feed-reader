<?php

declare(strict_types=1);

namespace App\Service\Crypto\Exception;

/**
 * A wrong master key, an edited row and a row bound to another owner are indistinguishable on purpose: telling them
 * apart would only help someone probing the store.
 */
final class SecretUnreadableException extends \RuntimeException
{
}
