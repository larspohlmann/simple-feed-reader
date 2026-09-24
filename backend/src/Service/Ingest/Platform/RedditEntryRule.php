<?php

declare(strict_types=1);

namespace App\Service\Ingest\Platform;

use App\Enum\CommentsLoad;
use App\Service\Discussion\Discussion;
use App\Service\Parser\ParsedEntry;
use App\Service\Url\AbsoluteHttpUrl;

final readonly class RedditEntryRule implements PlatformEntryRule
{
    private const string REDDIT_HOST = '#(^|\.)(reddit\.com|redd\.it)$#i';
    private const string THREAD_PATH = '#/comments/[a-z0-9]+(/|$)#i';
    private const string LINK_TARGET = "#<a\\s+href\\s*=\\s*\x22([^\x22]+)\x22>\\[link]</a>#";

    /**
     * Anchored on the "submitted by" byline's own `/user/` link, not just the
     * words "submitted by" — a self post's body text can contain that phrase
     * too, and an unanchored match would eat everything from there onward.
     */
    private const string FOOTER = "#(?:\\s|&\\#32;)*submitted by(?:\\s|&\\#32;)*"
        . "<a\\s+href\\s*=\\s*\x22[^\x22]*/user/[^\x22]*\x22>.*?\\[comments]</a>\\s*</span>#s";

    public function supports(ParsedEntry $entry): bool
    {
        return $entry->url !== null
            && self::isRedditHosted($entry->url)
            && preg_match(self::THREAD_PATH, (string) parse_url($entry->url, \PHP_URL_PATH)) === 1;
    }

    public function apply(ParsedEntry $entry): ParsedEntry
    {
        $thread = self::withoutQuery((string) $entry->url);
        $footer = self::footerOf($entry->contentHtml);

        return $entry->withPlatformRewrite(
            self::externalArticle($footer),
            self::withoutFooter($entry->contentHtml, $footer),
            Discussion::withCommentsFeed($thread, rtrim($thread, '/') . '/.rss', CommentsLoad::Auto)
                ->withOpeningPostBody(),
        );
    }

    private static function footerOf(?string $contentHtml): ?string
    {
        if ($contentHtml === null || preg_match(self::FOOTER, $contentHtml, $match) !== 1) {
            return null;
        }

        return $match[0];
    }

    /** The article link comes from the footer span itself, never the post body — a body can carry its own [link] text. */
    private static function externalArticle(?string $footer): ?string
    {
        if ($footer === null || preg_match(self::LINK_TARGET, $footer, $match) !== 1) {
            return null;
        }
        $target = AbsoluteHttpUrl::orNull(html_entity_decode($match[1], \ENT_QUOTES | \ENT_HTML5));

        return $target === null || self::isRedditHosted($target) ? null : $target;
    }

    private static function withoutFooter(?string $contentHtml, ?string $footer): ?string
    {
        if ($contentHtml === null || $footer === null) {
            return $contentHtml;
        }

        return str_replace($footer, '', $contentHtml);
    }

    private static function isRedditHosted(string $url): bool
    {
        return preg_match(self::REDDIT_HOST, (string) parse_url($url, \PHP_URL_HOST)) === 1;
    }

    private static function withoutQuery(string $url): string
    {
        return strtok($url, '?#') ?: $url;
    }
}
