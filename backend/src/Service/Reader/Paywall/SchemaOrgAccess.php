<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use App\Service\Html\JsonLd;
use Dom\HTMLDocument;

/**
 * The publisher's own paywall declaration: schema.org `isAccessibleForFree`,
 * the markup Google documents for paywalled content. Read from the raw page,
 * because FetchedPageNormalizer strips every <script> from the normalized one.
 */
final readonly class SchemaOrgAccess
{
    private const string KEY = 'isAccessibleForFree';

    public static function declaredIn(HTMLDocument $rawPage): AccessDeclaration
    {
        $declarations = [];
        foreach (JsonLd::scriptsIn($rawPage) as $script) {
            array_push($declarations, ...self::declarationsIn(JsonLd::decode($script)));
        }

        if (\in_array(false, $declarations, true)) {
            return AccessDeclaration::Paywalled;
        }

        return $declarations === [] ? AccessDeclaration::Undeclared : AccessDeclaration::Free;
    }

    /**
     * @param array<mixed> $block
     *
     * @return list<bool> every isAccessibleForFree in the block, as a boolean
     */
    private static function declarationsIn(array $block): array
    {
        $declarations = [];
        foreach (JsonLd::nodesIn($block) as $node) {
            $declared = self::asBoolean($node[self::KEY] ?? null);
            if ($declared !== null) {
                $declarations[] = $declared;
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
