<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use App\Service\Html\JsonLd;
use App\Service\Reader\Paywall\Model\AccessDeclaration;
use Dom\HTMLDocument;

/**
 * The publisher's own paywall declaration: schema.org `isAccessibleForFree`,
 * the markup Google documents for paywalled content. Read from the raw page,
 * because FetchedPageNormalizer strips every <script> from the normalized one.
 */
final readonly class SchemaOrgAccess
{
    private const string KEY = 'isAccessibleForFree';

    public static function declaredIn(HTMLDocument $rawDocument): AccessDeclaration
    {
        $sawDeclaration = false;
        foreach (JsonLd::scriptsIn($rawDocument) as $script) {
            foreach (JsonLd::nodesIn(JsonLd::decode($script)) as $node) {
                $declared = self::asBoolean($node[self::KEY] ?? null);
                if ($declared === false) {
                    return AccessDeclaration::Paywalled;
                }
                $sawDeclaration = $sawDeclaration || $declared === true;
            }
        }

        return $sawDeclaration ? AccessDeclaration::Free : AccessDeclaration::Undeclared;
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
