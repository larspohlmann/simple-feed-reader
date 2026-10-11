<?php

declare(strict_types=1);

namespace App\Service\Ingest\PlatformEntryRule;

use App\Service\Ingest\Support\AtPostUri;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Support\EntryTitle;

/** Bluesky's RSS puts a placeholder line where a post embeds something; neither the body nor the title keeps it. */
final readonly class BlueskyEntryRule implements PlatformEntryRuleInterface
{
    private const string ESCAPED_PLACEHOLDER = '\[contains quote post or other embedded content\]';
    private const string PLACEHOLDER_PATTERN = '#<p>\s*' . self::ESCAPED_PLACEHOLDER . '\s*</p>'
        . '|(?:<br\s*/?>)?\s*' . self::ESCAPED_PLACEHOLDER . '#u';

    public function supports(ParsedEntryModel $entry): bool
    {
        return AtPostUri::matches($entry->guid);
    }

    public function apply(ParsedEntryModel $entry): ParsedEntryModel
    {
        $summary = self::withoutPlaceholder($entry->summary);
        $contentHtml = self::withoutPlaceholder($entry->contentHtml);
        $headline = $entry->headline->derived ? EntryTitle::of(null, $contentHtml ?? $summary) : $entry->headline;

        return $entry->withPostText($headline, $summary, $contentHtml);
    }

    private static function withoutPlaceholder(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $kept = trim(preg_replace(self::PLACEHOLDER_PATTERN, '', $text) ?? $text);

        return $kept === '' ? null : $kept;
    }
}
