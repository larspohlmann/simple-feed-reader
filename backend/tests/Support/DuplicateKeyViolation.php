<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver\AbstractException as DriverAbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** The `UniqueConstraintViolationException` a flush throws on a duplicate key, built the same way at every site. */
final class DuplicateKeyViolation
{
    public static function exception(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(
            new class ('duplicate key', '23000', 1062) extends DriverAbstractException {
            },
            null,
        );
    }
}
