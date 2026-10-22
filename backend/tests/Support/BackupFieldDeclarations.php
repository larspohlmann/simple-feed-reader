<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\Preferences;
use App\Entity\SavedEntryOrigin;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Backup\Support\BackupSchema;

/**
 * The backed-up fields, checked both ways from this one list: BackupSchemaCoverageTest proves the exporter writes each,
 * AccountRestorerTest that a Line DTO reads it back on restore.
 */
final class BackupFieldDeclarations
{
    /**
     * Doctrine class => [field or association => exported JSON key, or a list of keys when one field is written as
     * several]. BackupSchemaCoverageTest keeps the NOT_BACKED_UP and NEVER_BACKED_UP counterparts.
     */
    public const array BACKED_UP = [
        User::class => [
            'locale' => 'locale',
        ],
        Preferences::class => [
            'scrapeFallbackEnabled' => 'scrapeFallbackEnabled',
            'magazineStyle' => 'magazineStyle',
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
            'includeInSavedSearches' => 'includeInSavedSearches',
        ],
        SubscriptionTag::class => [
            'tag' => 'tags.name',
            'position' => 'tags.position',
        ],
        Entry::class => [
            'feed' => 'feedUrl',
            'guid.value' => 'guid', 'guid.hash' => 'guidHash', 'location.url' => 'url',
            'headline.title' => 'title', 'headline.derived' => 'titleDerived',
            'share.kind' => 'shareKind',
            // The quote rides in its entry's line as one object; its nested quote is one object inside it.
            'share.quotedPost.url' => ['quote', 'quote.url'],
            'share.quotedPost.authorName' => 'quote.authorName',
            'share.quotedPost.authorHandle' => 'quote.authorHandle',
            'share.quotedPost.authorAvatarUrl' => 'quote.authorAvatarUrl',
            'share.quotedPost.postedAt' => 'quote.postedAt',
            'share.quotedPost.text' => 'quote.text',
            'share.quotedPost.media' => 'quote.media',
            'share.quotedPost.nestedUrl' => 'quote.nestedQuote',
            'share.quotedPost.nestedHandle' => 'quote.nestedQuote',
            'author.name' => 'author', 'author.profileUrl' => 'authorUrl', 'author.avatarUrl' => 'authorAvatarUrl',
            'summary' => 'summary',
            'contentHtml' => 'contentHtml',
            'image.url' => 'imageUrl', 'image.width' => 'imageWidth',
            'image.height' => 'imageHeight', 'image.renditions' => 'imageRenditions',
            'mediaSet.media' => 'media', 'mediaSet.attachments' => 'attachments',
            'publishedAt' => 'publishedAt', 'createdAt' => 'createdAt',
            'effectiveDate' => 'effectiveDate',
            'discussion.url' => 'discussionUrl',
            'discussion.commentsFeedUrl' => 'commentsFeedUrl',
            'discussion.commentsLoad' => 'commentsLoad',
        ],
        LinkCard::class => [
            // A card rides in its entry's line, in post order, so neither the entry nor the position is a key.
            'entry' => 'linkCards', 'position' => 'linkCards',
            'url' => 'linkCards.url', 'title' => 'linkCards.title', 'description' => 'linkCards.description',
            'imageUrl' => 'linkCards.imageUrl', 'siteName' => 'linkCards.siteName',
        ],
        SavedEntryOrigin::class => [
            'entry' => 'origin', 'title' => 'origin.title', 'faviconUrl' => 'origin.faviconUrl',
        ],
        EntryState::class => [
            // Both halves: guidHash picks the entry within its feed, feedUrl the feed. Declaring only one would let
            // entryStateLine() drop the other unnoticed.
            'entry' => ['feedUrl', 'guidHash'],
            'isHidden' => 'isHidden', 'isFavorite' => 'isFavorite', 'isKept' => 'isKept',
            'hiddenAt' => 'hiddenAt', 'isViewed' => 'isViewed', 'viewedAt' => 'viewedAt',
        ],
    ];

    /** Doctrine class => the `kind` of the line its fields are written on. */
    public const array KIND_OF = [
        User::class => BackupSchema::KIND_ACCOUNT,
        Preferences::class => BackupSchema::KIND_ACCOUNT,
        Tag::class => BackupSchema::KIND_TAG,
        SavedSearch::class => BackupSchema::KIND_SAVED_SEARCH,
        Feed::class => BackupSchema::KIND_FEED,
        Subscription::class => BackupSchema::KIND_SUBSCRIPTION,
        SubscriptionTag::class => BackupSchema::KIND_SUBSCRIPTION,
        Entry::class => BackupSchema::KIND_ENTRY,
        LinkCard::class => BackupSchema::KIND_ENTRY,
        SavedEntryOrigin::class => BackupSchema::KIND_ENTRY,
        EntryState::class => BackupSchema::KIND_ENTRY_STATE,
    ];

    private function __construct()
    {
        // Constants only; never instantiated.
    }
}
