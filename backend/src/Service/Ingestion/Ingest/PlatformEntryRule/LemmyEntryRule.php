<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest\PlatformEntryRule;

use App\Entity\Discussion;
use App\Service\Ingestion\Ingest\Support\LemmyPostHeader;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Foundation\Url\Support\LemmyPostUrl;

final readonly class LemmyEntryRule implements PlatformEntryRuleInterface
{
    public function supports(ParsedEntryModel $entry): bool
    {
        return $this->postUrl($entry) !== null
            && str_starts_with(ltrim((string) $entry->contentHtml), 'submitted by');
    }

    public function apply(ParsedEntryModel $entry): ParsedEntryModel
    {
        $pageUrl = $this->postUrl($entry);
        $post = LemmyPostHeader::read((string) $entry->contentHtml);
        if ($pageUrl === null || $post === null) {
            return $entry;
        }

        return $entry->asDiscussionThread(Discussion::platformThread($pageUrl))
            ->withContentHtml($post->bodyHtml)
            ->withAuthor($post->authorName, $post->profileUrl);
    }

    private function postUrl(ParsedEntryModel $entry): ?string
    {
        foreach ([$entry->url, $entry->guid] as $candidate) {
            if ($candidate !== null && LemmyPostUrl::parse($candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }
}
