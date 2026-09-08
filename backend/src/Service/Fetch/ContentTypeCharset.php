<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/** The charset label a Content-Type header carries, or null when it names none. */
final class ContentTypeCharset
{
    private const string CHARSET_PARAMETER = '/;\s*charset\s*=\s*"?([^";\s]+)"?/i';

    public static function of(?string $contentType): ?string
    {
        if ($contentType === null || preg_match(self::CHARSET_PARAMETER, $contentType, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
