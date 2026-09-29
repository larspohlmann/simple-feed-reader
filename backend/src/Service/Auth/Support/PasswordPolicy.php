<?php

declare(strict_types=1);

namespace App\Service\Auth\Support;

/** 12 characters and no composition rules: length beats character classes, and a memorable passphrase gets kept. */
final class PasswordPolicy
{
    public const int MINIMUM_LENGTH = 12;
    public const int MAXIMUM_LENGTH = 4096;

    public static function isLongEnough(string $password): bool
    {
        return mb_strlen($password) >= self::MINIMUM_LENGTH;
    }
}
