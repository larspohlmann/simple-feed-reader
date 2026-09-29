<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The locales the UI ships translations for, so the values User::$locale may hold. Constants, not a backed enum: the
 * locale stays a plain string, and UpdateLocaleRequest, SignupUserFactory and translation.yaml read this one list.
 */
final class SupportedLocale
{
    public const string ENGLISH = 'en';
    public const string GERMAN = 'de';

    public const array ALL = [self::ENGLISH, self::GERMAN];
}
