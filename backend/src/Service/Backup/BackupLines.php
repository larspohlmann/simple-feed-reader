<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\Entry;
use App\Entity\EntryMedia;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Backup\Dto\BackupTotals;

/**
 * Shapes one backup record into its encoded NDJSON line. Every "…Line"
 * method here is a pure mapping from one entity to the JSON schema
 * BackupReader parses back — the exporter decides which lines to call and in
 * what order, this class owns only what each line looks like. The instant a
 * header carries is read once from the clock by the exporter, so every part
 * of one export shares it — this class only formats the value it is given.
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
    ];

    public function foundationHeader(BackupProvenance $provenance, int $parts, BackupTotals $totals): string
    {
        return $this->encode([
            ...$this->headerFields($provenance, 0),
            'parts' => $parts,
            'totals' => ['entries' => $totals->entries, 'entryStates' => $totals->entryStates],
        ]);
    }

    public function entryPartHeader(BackupProvenance $provenance, int $part): string
    {
        return $this->encode([
            ...$this->headerFields($provenance, $part),
            'parts' => null,
            'totals' => null,
        ]);
    }

    public function accountLine(User $user): string
    {
        return $this->encode([
            'kind' => BackupSchema::KIND_ACCOUNT,
            'locale' => $user->getLocale(),
            'scrapeFallbackEnabled' => $user->getPreferences()->isScrapeFallbackEnabled(),
            'magazineStyle' => $user->getPreferences()->getMagazineStyle()->value,
        ]);
    }

    public function tagLine(Tag $tag): string
    {
        return $this->encode([
            'kind' => BackupSchema::KIND_TAG,
            'name' => $tag->getName(),
            'color' => $tag->getColor(),
            'icon' => $tag->getIcon(),
            'position' => $tag->getPosition(),
        ]);
    }

    public function savedSearchLine(SavedSearch $savedSearch): string
    {
        return $this->encode([
            'kind' => BackupSchema::KIND_SAVED_SEARCH,
            'term' => $savedSearch->getTerm(),
            'wholeWord' => $savedSearch->isWholeWord(),
            'phrase' => $savedSearch->isPhrase(),
            'position' => $savedSearch->getPosition(),
        ]);
    }

    public function feedLine(Feed $feed): string
    {
        return $this->encode([
            'kind' => BackupSchema::KIND_FEED,
            'url' => $feed->getUrl(),
            'siteUrl' => $feed->getSiteUrl(),
            'title' => $feed->getTitle(),
            'description' => $feed->getDescription(),
            'faviconUrl' => $feed->getFaviconUrl(),
            'imageUrl' => $feed->getImageUrl(),
            'sourceFormat' => $feed->getSourceFormat(),
        ]);
    }

    public function subscriptionLine(Subscription $subscription): string
    {
        return $this->encode([
            'kind' => BackupSchema::KIND_SUBSCRIPTION,
            'feedUrl' => $subscription->getFeed()->getUrl(),
            'customTitle' => $subscription->getCustomTitle(),
            'position' => $subscription->getPosition(),
            'markedReadUntil' => $this->formatDateOrNull($subscription->getMarkedReadUntil()),
            'createdAt' => $this->formatDate($subscription->getCreatedAt()),
            'tags' => $this->subscriptionTagRefs($subscription),
            'includeInAllItems' => $subscription->isIncludeInAllItems(),
            'includeInForYou' => $subscription->isIncludeInForYou(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function subscriptionTagRefs(Subscription $subscription): array
    {
        return array_map(
            static fn (SubscriptionTag $subscriptionTag): array => [
                'name' => $subscriptionTag->getTag()->getName(),
                'position' => $subscriptionTag->getPosition(),
            ],
            $subscription->getSubscriptionTags(),
        );
    }

    public function entryLine(Entry $entry, string $feedUrl): string
    {
        $discussion = $entry->getDiscussion();

        return $this->encode([
            'kind' => BackupSchema::KIND_ENTRY,
            'feedUrl' => $feedUrl,
            'guid' => $entry->getGuid(),
            'guidHash' => $entry->getGuidHash(),
            'url' => $entry->getUrl(),
            'discussionUrl' => $discussion->url,
            'commentsFeedUrl' => $discussion->commentsFeedUrl,
            'commentsLoad' => $discussion->commentsLoad?->value,
            'title' => $entry->getTitle(),
            'author' => $entry->getAuthor(),
            'summary' => $entry->getSummary(),
            'contentHtml' => $entry->getContentHtml(),
            'imageUrl' => $entry->getImageUrl(),
            'imageWidth' => $entry->getImageWidth(),
            'imageHeight' => $entry->getImageHeight(),
            'media' => EntryMedia::toJsonList($entry->getMedia()),
            'attachments' => EntryMedia::toJsonList($entry->getAttachments()),
            'publishedAt' => $this->formatDateOrNull($entry->getPublishedAt()),
            'createdAt' => $this->formatDate($entry->getCreatedAt()),
            'effectiveDate' => $this->formatDate($entry->getEffectiveDate()),
        ]);
    }

    public function entryStateLine(EntryState $state, string $feedUrl): string
    {
        return $this->encode([
            'kind' => BackupSchema::KIND_ENTRY_STATE,
            'feedUrl' => $feedUrl,
            'guidHash' => $state->getEntry()->getGuidHash(),
            'isHidden' => $state->isHidden(),
            'isFavorite' => $state->isFavorite(),
            'isKept' => $state->isKept(),
            'hiddenAt' => $this->formatDateOrNull($state->getHiddenAt()),
            'isViewed' => $state->isViewed(),
            'viewedAt' => $this->formatDateOrNull($state->getViewedAt()),
        ]);
    }

    private function formatDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }

    private function formatDateOrNull(?\DateTimeImmutable $date): ?string
    {
        return null === $date ? null : $this->formatDate($date);
    }

    /**
     * @return array<string, mixed>
     */
    private function headerFields(BackupProvenance $provenance, int $part): array
    {
        return [
            'kind' => BackupSchema::KIND_HEADER,
            'schemaVersion' => BackupSchema::VERSION,
            'createdAt' => $this->formatDate($provenance->createdAt),
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
        return $this->encode([
            'kind' => BackupSchema::KIND_FOOTER,
            'counts' => array_replace(self::NO_COUNTS, $counts),
        ]);
    }

    /**
     * @param array<string, mixed> $line
     */
    private function encode(array $line): string
    {
        return json_encode($line, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
