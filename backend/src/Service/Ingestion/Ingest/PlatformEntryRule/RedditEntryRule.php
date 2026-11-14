<?php

declare(strict_types=1);

namespace App\Service\Ingest\PlatformEntryRule;

use App\Entity\Discussion;
use App\Enum\CommentsLoad;
use App\Service\Parser\Model\ParsedEntryModel;

final readonly class RedditEntryRule implements PlatformEntryRuleInterface
{
    private const string REDDIT_HOST = '#(^|\.)(reddit\.com|redd\.it)$#i';
    private const string THREAD_PATH = '#/comments/[a-z0-9]+(/|$)#i';

    public function supports(ParsedEntryModel $entry): bool
    {
        return $entry->url !== null
            && preg_match(self::REDDIT_HOST, (string) parse_url($entry->url, \PHP_URL_HOST)) === 1
            && preg_match(self::THREAD_PATH, (string) parse_url($entry->url, \PHP_URL_PATH)) === 1;
    }

    public function apply(ParsedEntryModel $entry): ParsedEntryModel
    {
        $thread = self::withoutQuery((string) $entry->url);

        return $entry->asDiscussionThread(
            Discussion::withCommentsFeed($thread, rtrim($thread, '/') . '/.rss', CommentsLoad::Auto),
        );
    }

    private static function withoutQuery(string $url): string
    {
        return strtok($url, '?#') ?: $url;
    }
}
