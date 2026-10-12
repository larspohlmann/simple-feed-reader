<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Text\Support\HtmlAnchor;
use App\Service\Text\Support\HtmlEscape;
use App\Service\Text\Support\ParagraphedText;
use App\Service\Url\Support\AbsoluteHttpUrl;

/** A Bluesky post's text as HTML: its facets linked by their UTF-8 byte ranges, everything else escaped. */
final class BlueskyRichText
{
    private const string LINK = 'app.bsky.richtext.facet#link';
    private const string MENTION = 'app.bsky.richtext.facet#mention';
    private const string TAG = 'app.bsky.richtext.facet#tag';
    private const string HASHTAG = 'https://bsky.app/hashtag/';

    /** @param list<JsonNodeModel> $facets */
    public static function asHtml(string $text, array $facets): ?string
    {
        if ('' === trim($text)) {
            return null;
        }

        $html = '';
        $offset = 0;
        foreach (self::usableSpans($text, $facets) as [$start, $end, $href]) {
            $html .= HtmlEscape::text(substr($text, $offset, $start - $offset))
                . self::link($href, substr($text, $start, $end - $start));
            $offset = $end;
        }
        $html .= HtmlEscape::text(substr($text, $offset));

        return ParagraphedText::asHtml($html, static fn (string $line): string => $line);
    }

    /**
     * @param list<JsonNodeModel> $facets
     *
     * @return list<array{int, int, string}> in text order, an overlapping one dropped
     */
    private static function usableSpans(string $text, array $facets): array
    {
        $spans = [];
        foreach ($facets as $facet) {
            $span = self::spanOf($text, $facet);
            if (null !== $span) {
                $spans[] = $span;
            }
        }
        usort($spans, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        $kept = [];
        $reached = 0;
        foreach ($spans as $span) {
            if ($span[0] >= $reached) {
                $kept[] = $span;
                $reached = $span[1];
            }
        }

        return $kept;
    }

    /** @return array{int, int, string}|null */
    private static function spanOf(string $text, JsonNodeModel $facet): ?array
    {
        $index = $facet->node('index');
        $start = $index->int('byteStart');
        $end = $index->int('byteEnd');
        $href = self::hrefOf($facet->first('features'));
        if (null === $start || null === $end || null === $href || $start < 0 || $end <= $start) {
            return null;
        }
        // A range past the text, or one splitting a character, is not a range of this text.
        if ($end > \strlen($text) || !mb_check_encoding(substr($text, $start, $end - $start), 'UTF-8')) {
            return null;
        }

        return [$start, $end, $href];
    }

    private static function hrefOf(JsonNodeModel $feature): ?string
    {
        return match ($feature->type()) {
            self::LINK => AbsoluteHttpUrl::orNull($feature->string('uri')),
            self::MENTION => self::profileOf($feature->string('did')),
            self::TAG => self::hashtagOf($feature->string('tag')),
            default => null,
        };
    }

    private static function profileOf(?string $did): ?string
    {
        return null === $did ? null : AtPostUri::profileUrl($did);
    }

    private static function hashtagOf(?string $tag): ?string
    {
        return null === $tag ? null : self::HASHTAG . rawurlencode($tag);
    }

    /** A link must stay on one line: ParagraphedText splits at every line break. */
    private static function link(string $href, string $slice): string
    {
        return HtmlAnchor::to($href, str_replace(["\r\n", "\r", "\n"], ' ', $slice));
    }

    private function __construct()
    {
    }
}
