<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Parser\Model\FeedNamespace;

/** WordPress plugins emit `<media:content>` without `xmlns:media`; libxml then files the element under no namespace. */
final class UndeclaredPrefixes
{
    private const string ROOT_START_TAG = '/<(?![?!])[A-Za-z_][\w.:-]*/';

    public static function declaredIn(string $xml): string
    {
        $declarations = self::missingDeclarations($xml);
        if ($declarations === '') {
            return $xml;
        }

        return (string) preg_replace_callback(
            self::ROOT_START_TAG,
            static fn (array $match): string => $match[0] . $declarations,
            $xml,
            1,
        );
    }

    private static function missingDeclarations(string $xml): string
    {
        $declarations = '';
        foreach (FeedNamespace::cases() as $namespace) {
            $prefix = $namespace->conventionalPrefix();
            if ($prefix !== null && self::usesUndeclared($xml, $prefix)) {
                $declarations .= \sprintf(' xmlns:%s="%s"', $prefix, $namespace->value);
            }
        }

        return $declarations;
    }

    private static function usesUndeclared(string $xml, string $prefix): bool
    {
        return str_contains($xml, '<' . $prefix . ':') && !str_contains($xml, 'xmlns:' . $prefix . '=');
    }

    private function __construct()
    {
    }
}
