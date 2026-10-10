<?php

declare(strict_types=1);

namespace App\Service\Ingest\PlatformEntryRule;

use App\Service\Ingest\Support\AtPostUri;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedTitleModel;
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
        $title = $entry->titleDerived ? self::postTitle($contentHtml ?? $summary) : $entry->parsedTitle();

        return $entry->withPostText($title, $summary, $contentHtml);
    }

    private static function postTitle(?string $text): ParsedTitleModel
    {
        $title = EntryTitle::of(null, $text);

        return $title->derived ? $title : ParsedTitleModel::untitledPost();
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
