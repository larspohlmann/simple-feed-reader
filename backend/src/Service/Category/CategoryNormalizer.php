<?php

declare(strict_types=1);

namespace App\Service\Category;

use App\Service\Parser\ParsedCategory;

/**
 * Turns the raw categories a feed declared for one entry into the rows to
 * persist: trimmed, de-duplicated on (canonical key, scheme), capped against
 * abusive feeds. Pure — no I/O.
 */
final class CategoryNormalizer
{
    private const int MAX_PER_ENTRY = 30;
    private const int LABEL_MAX = 128;
    private const int SCHEME_MAX = 255;

    /**
     * @param list<ParsedCategory> $raw
     *
     * @return list<NormalizedCategory>
     */
    public function normalize(array $raw): array
    {
        $byIdentity = [];
        foreach ($raw as $category) {
            $normalized = $this->normalizeOne($category);
            if ($normalized === null) {
                continue;
            }
            $byIdentity[$normalized->identity()] ??= $normalized;
            if (\count($byIdentity) >= self::MAX_PER_ENTRY) {
                break;
            }
        }

        return array_values($byIdentity);
    }

    private function normalizeOne(ParsedCategory $category): ?NormalizedCategory
    {
        $label = trim($category->label);
        if ($label === '') {
            return null;
        }

        $collapsed = (string) preg_replace('/\s+/u', ' ', $label);
        $canonicalKey = mb_substr(mb_strtolower($collapsed), 0, self::LABEL_MAX);

        return new NormalizedCategory(
            $canonicalKey,
            mb_substr($label, 0, self::LABEL_MAX),
            mb_substr(trim($category->scheme ?? ''), 0, self::SCHEME_MAX),
        );
    }
}
