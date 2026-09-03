<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The locales the UI ships translations for. A constants holder rather than a
 * backed enum, matching {@see SourceFormat}: `User::$locale` and the request
 * DTOs that validate it stay plain strings, so this just needs to be the one
 * list every call site checks against, not a new type to convert.
 *
 * Shared between {@see \App\Dto\Me\UpdateLocaleRequest} (rejects an unsupported
 * value) and {@see \App\Service\Auth\RegistrationService} (falls back to
 * English) — those two used to keep their own copies and had already drifted on
 * what to do with an unsupported value. Also wired into
 * config/packages/translation.yaml's `enabled_locales` via `!php/const`.
 */
final class SupportedLocale
{
    public const string ENGLISH = 'en';
    public const string GERMAN = 'de';

    public const array ALL = [self::ENGLISH, self::GERMAN];
}
