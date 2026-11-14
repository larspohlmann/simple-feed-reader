<?php

declare(strict_types=1);

namespace App\Service\Reader\Support;

/**
 * The ids a stylesheet hides outright: a top-level rule whose selector is a bare `#id` and that sets `display: none`.
 * A rule inside an at-rule hides only under its condition, so it never counts.
 */
final class StylesheetHiddenIds
{
    private const string COMMENT_PATTERN = '#/\*.*?\*/#s';
    private const string AT_RULE_PATTERN = '/@[^{};]*(?:;|(\{(?:[^{}]++|(?1))*\}))/';
    private const string RULE_PATTERN = '/([^{}]+)\{([^{}]*)\}/';
    private const string HIDING_DECLARATION_PATTERN = '/(?:^|;)\s*display\s*:\s*none\s*(?:!important\s*)?(?:;|$)/i';
    private const string ID_SELECTOR_PATTERN = '/^#(-?[_a-zA-Z][\w-]*)$/';

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function in(string $stylesheet): array
    {
        $ids = [];
        foreach (self::topLevelRules($stylesheet) as [$selectors, $declarations]) {
            if (preg_match(self::HIDING_DECLARATION_PATTERN, $declarations) === 1) {
                $ids = [...$ids, ...self::idsOf($selectors)];
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<array{string, string}> */
    private static function topLevelRules(string $stylesheet): array
    {
        $withoutComments = preg_replace(self::COMMENT_PATTERN, '', $stylesheet) ?? '';
        $topLevel = preg_replace(self::AT_RULE_PATTERN, '', $withoutComments) ?? '';
        preg_match_all(self::RULE_PATTERN, $topLevel, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match): array => [$match[1], $match[2]], $matches);
    }

    /** @return list<string> */
    private static function idsOf(string $selectors): array
    {
        $ids = [];
        foreach (explode(',', $selectors) as $selector) {
            if (preg_match(self::ID_SELECTOR_PATTERN, trim($selector), $match) === 1) {
                $ids[] = $match[1];
            }
        }

        return $ids;
    }
}
