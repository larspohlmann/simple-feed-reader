<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit\Model;

/**
 * The deep link that opens one audited article in the SPA, in the shape frontend/src/app/reader/slug.ts writes: a
 * second spelling that can only drift in the cosmetic slug, since the id alone opens the entry.
 */
final readonly class ReaderLinkModel
{
    public function __construct(private string $baseUrl)
    {
    }

    public function to(SampledEntryModel $entry): string
    {
        return \sprintf(
            '%s/?subscription=%d&entry=%s',
            rtrim($this->baseUrl, '/'),
            $entry->subscriptionId,
            rawurlencode($this->entryParam($entry)),
        );
    }

    private function entryParam(SampledEntryModel $entry): string
    {
        $slug = $this->slug($entry->title);

        return $slug === '' ? (string) $entry->entryId : $entry->entryId . '-' . $slug;
    }

    private function slug(string $title): string
    {
        $ascii = (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $title);
        $hyphenated = (string) preg_replace('/[^a-z0-9]+/', '-', $ascii);

        return trim(mb_substr(trim($hyphenated, '-'), 0, 80), '-');
    }
}
