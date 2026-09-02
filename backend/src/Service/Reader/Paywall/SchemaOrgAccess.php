<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

/**
 * The publisher's own paywall declaration: schema.org `isAccessibleForFree`,
 * the markup Google documents for paywalled content. Read from the raw source
 * because FetchedPageNormalizer strips every <script> before the shared parse.
 */
final readonly class SchemaOrgAccess
{
    private const string JSON_LD_PATTERN = '#<script\b[^>]*application/ld\+json[^>]*>(.*?)</script\s*>#is';
    private const string KEY = 'isAccessibleForFree';

    /** True when the page declares a paywall, false when it declares free access, null when it says nothing. */
    public static function paywalledIn(string $html): ?bool
    {
        $verdict = null;
        preg_match_all(self::JSON_LD_PATTERN, $html, $blocks);
        foreach ($blocks[1] as $json) {
            $decoded = json_decode(trim($json), true);
            if (!\is_array($decoded)) {
                continue;
            }
            foreach (self::declarationsIn($decoded) as $accessibleForFree) {
                if (!$accessibleForFree) {
                    return true;
                }
                $verdict = false;
            }
        }

        return $verdict;
    }

    /**
     * @param array<mixed> $node
     *
     * @return list<bool> every isAccessibleForFree in the tree, as a boolean
     */
    private static function declarationsIn(array $node): array
    {
        $declarations = [];
        $declared = self::asBoolean($node[self::KEY] ?? null);
        if ($declared !== null) {
            $declarations[] = $declared;
        }
        foreach ($node as $child) {
            if (\is_array($child)) {
                array_push($declarations, ...self::declarationsIn($child));
            }
        }

        return $declarations;
    }

    private static function asBoolean(mixed $value): ?bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (!\is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'true', 'http://schema.org/true', 'https://schema.org/true' => true,
            'false', 'http://schema.org/false', 'https://schema.org/false' => false,
            default => null,
        };
    }
}
