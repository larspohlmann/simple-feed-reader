<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * Builds the deep link that opens one audited article in the running SPA, in the
 * shape frontend/src/app/reader/slug.ts writes and parses: the subscription
 * selects the list, the id opens the entry, and the slug is cosmetic.
 *
 * A second spelling of that rule, in the language the report is written in. It
 * can only drift in the slug, which is decoration — the id still opens the
 * article — so the duplication costs nothing a reader of the report would notice.
 */
final readonly class ReaderLink
{
    public function __construct(private string $baseUrl)
    {
    }

    public function to(SampledEntry $entry): string
    {
        return \sprintf(
            '%s/?subscription=%d&entry=%s',
            rtrim($this->baseUrl, '/'),
            $entry->subscriptionId,
            rawurlencode($this->entryParam($entry)),
        );
    }

    private function entryParam(SampledEntry $entry): string
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
