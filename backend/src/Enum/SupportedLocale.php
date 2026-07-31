<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The locales the UI ships translations for. A constants holder rather than a
 * backed enum, matching {@see SourceFormat}: `User::$locale` and the request
 * DTOs that validate it stay plain strings, so this only needs to be the one
 * list every call site checks a value against, not a new type to convert to
 * and from.
 *
 * Shared between {@see \App\Dto\Me\UpdateLocaleRequest} (rejects an
 * unsupported value) and {@see \App\Service\Auth\RegistrationService}
 * (silently falls back to English) — those two used to keep their own copies
 * of this list, and they had already drifted into disagreeing on what to do
 * with an unsupported value. Also wired into
 * config/packages/translation.yaml's `enabled_locales` via `!php/const`.
 */
final class SupportedLocale
{
    public const string ENGLISH = 'en';
    public const string GERMAN = 'de';

    public const array ALL = [self::ENGLISH, self::GERMAN];
}
