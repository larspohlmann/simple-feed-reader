<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\Entry;
use App\Entity\EntryMedia;
use App\Entity\ImageRendition;
use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Service\Backup\Dto\BackupTotals;
use App\Service\Backup\Model\BackupProvenanceModel;
use App\Service\Backup\Model\EntryCompanionsModel;
use App\Service\Backup\Support\BackupLineFormat;
use App\Service\Backup\Support\BackupSchema;

/**
 * Encodes one backup record as its NDJSON line, the schema BackupReader parses back. The exporter decides which
 * lines to write and in what order, and reads the header's instant once so every part shares it.
 */
final readonly class BackupLines
{
    private const array NO_COUNTS = [
        BackupSchema::KIND_TAG => 0,
        BackupSchema::KIND_SAVED_SEARCH => 0,
        BackupSchema::KIND_FEED => 0,
        BackupSchema::KIND_SUBSCRIPTION => 0,
        BackupSchema::KIND_ENTRY => 0,
        BackupSchema::KIND_ENTRY_STATE => 0,
        BackupSchema::KIND_ENTRY_SHARE => 0,
    ];

    public function foundationHeader(BackupProvenanceModel $provenance, int $parts, BackupTotals $totals): string
    {
        return BackupLineFormat::encode([
            ...$this->headerFields($provenance, 0),
            'parts' => $parts,
            'totals' => ['entries' => $totals->entries, 'entryStates' => $totals->entryStates],
        ]);
    }

    public function entryPartHeader(BackupProvenanceModel $provenance, int $part): string
    {
        return BackupLineFormat::encode([
            ...$this->headerFields($provenance, $part),
            'parts' => null,
            'totals' => null,
        ]);
    }

    public function entryLine(Entry $entry, string $feedUrl, EntryCompanionsModel $companions): string
    {
        $discussion = $entry->getDiscussion();

        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_ENTRY,
            'feedUrl' => $feedUrl,
            'guid' => $entry->getGuid(),
            'guidHash' => $entry->getGuidHash(),
            'url' => $entry->getUrl(),
            'discussionUrl' => $discussion->url,
            'commentsFeedUrl' => $discussion->commentsFeedUrl,
            'commentsLoad' => $discussion->commentsLoad?->value,
            'title' => $entry->getTitle(),
            'titleDerived' => $entry->isTitleDerived(),
            'shareKind' => $entry->getShareKind()?->value,
            'quote' => $this->quoteFields($entry->getQuotedPost()),
            'author' => $entry->getAuthor(),
            'authorUrl' => $entry->getAuthorUrl(),
            'authorAvatarUrl' => $entry->getAuthorAvatarUrl(),
            'summary' => $entry->getSummary(),
            'contentHtml' => $entry->getContentHtml(),
            'imageUrl' => $entry->getImageUrl(),
            'imageWidth' => $entry->getImageWidth(),
            'imageHeight' => $entry->getImageHeight(),
            'imageRenditions' => ImageRendition::toJsonList($entry->getImage()->getRenditions()),
            'media' => EntryMedia::toJsonList($entry->getMedia()),
            'attachments' => EntryMedia::toJsonList($entry->getAttachments()),
            'chapters' => EntryMedia::toJsonList($entry->getChapters()),
            'chaptersUrl' => $entry->getChaptersUrl(),
            'linkCards' => array_map(
                static fn (LinkCardContent $card): array => $card->toStored(),
                $companions->linkCards,
            ),
            'origin' => null === $companions->origin ? null : [
                'title' => $companions->origin->title,
                'faviconUrl' => $companions->origin->faviconUrl,
            ],
            'publishedAt' => BackupLineFormat::dateOrNull($entry->getPublishedAt()),
            'createdAt' => BackupLineFormat::date($entry->getCreatedAt()),
            'effectiveDate' => BackupLineFormat::date($entry->getEffectiveDate()),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function quoteFields(?QuotedPostContent $quote): ?array
    {
        if ($quote === null) {
            return null;
        }
        $nested = $quote->nestedQuote;

        return [
            'url' => $quote->url,
            'authorName' => $quote->authorName,
            'authorHandle' => $quote->authorHandle,
            'authorAvatarUrl' => $quote->authorAvatarUrl,
            'postedAt' => BackupLineFormat::dateOrNull($quote->postedAt),
            'text' => $quote->text,
            'media' => EntryMedia::toJsonList($quote->media),
            'nestedQuote' => $nested === null ? null : ['url' => $nested->url, 'handle' => $nested->handle],
            'linkCard' => $quote->linkCard?->toStored(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function headerFields(BackupProvenanceModel $provenance, int $part): array
    {
        return [
            'kind' => BackupSchema::KIND_HEADER,
            'schemaVersion' => BackupSchema::VERSION,
            'createdAt' => BackupLineFormat::date($provenance->createdAt),
            'sourceUrl' => $provenance->sourceUrl,
            'sourceEmail' => $provenance->sourceEmail,
            'backupId' => $provenance->backupId,
            'part' => $part,
        ];
    }

    /**
     * @param array<string, int> $counts keyed by BackupSchema::KIND_*; an absent kind counts zero
     */
    public function footerLine(array $counts): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_FOOTER,
            'counts' => array_replace(self::NO_COUNTS, $counts),
        ]);
    }
}
