<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Preferences;
use App\Entity\RecommendationSettings;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Backup\BackupSchema;

/**
 * The write-direction declarations `BackupSchemaCoverageTest` owns, lifted
 * out so a second test can be driven off the same source of truth rather
 * than a hand-kept copy that silently diverges.
 *
 * `BackupSchemaCoverageTest` asks the write question: does every `BACKED_UP`
 * field reach the exporter's output. That question alone is not enough — a
 * field can pass it and still be lost, because the exporter writes it but no
 * Line DTO reads it back on restore. `AccountRestorerTest` asks that read
 * question, driven off this same list, so a field added here is checked in
 * both directions rather than only the one a reviewer happened to think of
 * (#556).
 */
final class BackupFieldDeclarations
{
    /**
     * Doctrine class => [field or association => exported JSON key, or the
     * list of keys when one field is written as several — an entry
     * reference is a feed URL and a GUID hash together, and neither half
     * identifies an entry on its own].
     *
     * Mirrors what used to live inline in `BackupSchemaCoverageTest::BACKED_UP`;
     * that class still owns the `NOT_BACKED_UP` and `NEVER_BACKED_UP`
     * counterparts this list has no need of.
     */
    public const array BACKED_UP = [
        User::class => [
            'locale' => 'locale',
            'recommendationSettings' => 'recommendationSettings',
        ],
        Preferences::class => ['scrapeFallbackEnabled' => 'scrapeFallbackEnabled'],
        RecommendationSettings::class => [
            'guidancePrompt' => 'recommendationSettings.guidancePrompt',
            'profileText' => 'recommendationSettings.profileText',
            'favoritesCap' => 'recommendationSettings.favoritesCap',
            'keptCap' => 'recommendationSettings.keptCap',
            'viewedCap' => 'recommendationSettings.viewedCap',
            'candidatePoolSize' => 'recommendationSettings.candidatePoolSize',
            'lookbackDays' => 'recommendationSettings.lookbackDays',
            'picksLimit' => 'recommendationSettings.picksLimit',
            'contextWindow' => 'recommendationSettings.contextWindow',
            'batchCount' => 'recommendationSettings.batchCount',
            'debugEnabled' => 'recommendationSettings.debugEnabled',
            'autoGenerateIntervalHours' => 'recommendationSettings.autoGenerateIntervalHours',
            'showReasons' => 'recommendationSettings.showReasons',
        ],
        Tag::class => [
            'name' => 'name', 'color' => 'color', 'icon' => 'icon', 'position' => 'position',
        ],
        SavedSearch::class => [
            'term' => 'term', 'wholeWord' => 'wholeWord', 'phrase' => 'phrase', 'position' => 'position',
        ],
        Feed::class => [
            'url' => 'url', 'siteUrl' => 'siteUrl', 'title' => 'title',
            'description' => 'description', 'faviconUrl' => 'faviconUrl',
            'imageUrl' => 'imageUrl',
            'sourceFormat' => 'sourceFormat',
        ],
        Subscription::class => [
            'feed' => 'feedUrl',
            'customTitle' => 'customTitle',
            'position' => 'position',
            'markedReadUntil' => 'markedReadUntil',
            'createdAt' => 'createdAt',
            'subscriptionTags' => 'tags',
            'includeInAllItems' => 'includeInAllItems',
            'includeInForYou' => 'includeInForYou',
        ],
        SubscriptionTag::class => [
            'tag' => 'tags.name',
            'position' => 'tags.position',
        ],
        Entry::class => [
            'feed' => 'feedUrl',
            'guid' => 'guid', 'guidHash' => 'guidHash', 'url' => 'url',
            'title' => 'title', 'author' => 'author', 'summary' => 'summary',
            'contentHtml' => 'contentHtml',
            'image.url' => 'imageUrl', 'image.width' => 'imageWidth',
            'image.height' => 'imageHeight',
            'publishedAt' => 'publishedAt', 'createdAt' => 'createdAt',
            'effectiveDate' => 'effectiveDate',
        ],
        EntryState::class => [
            // Both halves, because both are load-bearing: guidHash picks the
            // entry out of a feed, feedUrl says which feed. Declaring only one
            // would leave the other claimed by nothing, and deleting it from
            // entryStateLine() would still pass.
            'entry' => ['feedUrl', 'guidHash'],
            'isHidden' => 'isHidden', 'isFavorite' => 'isFavorite', 'isKept' => 'isKept',
            'hiddenAt' => 'hiddenAt', 'isViewed' => 'isViewed', 'viewedAt' => 'viewedAt',
        ],
    ];

    /** Doctrine class => the `kind` of the line its fields are written on. */
    public const array KIND_OF = [
        User::class => BackupSchema::KIND_ACCOUNT,
        Preferences::class => BackupSchema::KIND_ACCOUNT,
        RecommendationSettings::class => BackupSchema::KIND_ACCOUNT,
        Tag::class => BackupSchema::KIND_TAG,
        SavedSearch::class => BackupSchema::KIND_SAVED_SEARCH,
        Feed::class => BackupSchema::KIND_FEED,
        Subscription::class => BackupSchema::KIND_SUBSCRIPTION,
        SubscriptionTag::class => BackupSchema::KIND_SUBSCRIPTION,
        Entry::class => BackupSchema::KIND_ENTRY,
        EntryState::class => BackupSchema::KIND_ENTRY_STATE,
    ];

    private function __construct()
    {
        // Constants only; never instantiated.
    }
}
