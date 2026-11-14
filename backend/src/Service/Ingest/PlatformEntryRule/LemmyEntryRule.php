<?php

declare(strict_types=1);

namespace App\Service\Ingest\PlatformEntryRule;

use App\Entity\Discussion;
use App\Service\Ingest\Support\LemmyPostHeader;
use App\Service\Parser\Model\ParsedEntryModel;

final readonly class LemmyEntryRule implements PlatformEntryRuleInterface
{
    private const string POST_URL = '#^https://[^/]+/post/\d+/?$#i';

    public function supports(ParsedEntryModel $entry): bool
    {
        return ($this->isPostUrl($entry->url) || $this->isPostUrl($entry->guid))
            && $entry->contentHtml !== null
            && LemmyPostHeader::read($entry->contentHtml) !== null;
    }

    public function apply(ParsedEntryModel $entry): ParsedEntryModel
    {
        $post = LemmyPostHeader::read((string) $entry->contentHtml);
        if ($post === null) {
            return $entry;
        }
        $pageUrl = $this->isPostUrl($entry->url) ? (string) $entry->url : $entry->guid;

        return $entry->asDiscussionThread(Discussion::platformThread($pageUrl))
            ->withContentHtml($post->bodyHtml)
            ->withAuthor($post->authorName, $post->profileUrl);
    }

    private function isPostUrl(?string $url): bool
    {
        return $url !== null && preg_match(self::POST_URL, $url) === 1;
    }
}
