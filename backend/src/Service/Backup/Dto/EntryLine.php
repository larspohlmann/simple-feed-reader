<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\EntryChapters;
use App\Entity\EntryMedia;
use App\Entity\EntryMedium;
use App\Entity\EntryOrigin;
use App\Entity\ImageRendition;
use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Entity\QuotedPostReference;
use App\Entity\SavedEntryOrigin;
use App\Enum\ShareKind;
use App\Service\Backup\Model\EntryCompanionsModel;
use App\Service\Backup\Support\BackupLineFormat;
use App\Service\Backup\Support\BackupSchema;
use App\Service\Backup\Support\LineField;
use App\Service\Backup\Support\LineFieldWithDefault;
use App\Service\LinkCard\Support\StorableLinkCard;
use App\Service\LinkCard\Support\StorableQuotedPost;
use App\Service\Url\Support\AbsoluteHttpUrl;

/**
 * One entry belonging to a feed the account subscribes to.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") pure data carrier that
 * mirrors the row 1:1, not a behavioural method.
 */
final readonly class EntryLine
{
    public function __construct(
        public string $feedUrl,
        public string $guid,
        public string $guidHash,
        public ?string $url,
        public string $title,
        public ?string $author,
        public ?string $summary,
        public ?string $contentHtml,
        public ?string $imageUrl,
        public ?int $imageWidth,
        public ?int $imageHeight,
        public ?\DateTimeImmutable $publishedAt,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $effectiveDate,
        /** @var list<array<string, mixed>> */
        public array $media = [],
        /** @var list<array<string, mixed>> */
        public array $attachments = [],
        public ?string $discussionUrl = null,
        public ?string $commentsFeedUrl = null,
        public ?string $commentsLoad = null,
        /** @var list<array<string, mixed>> */
        public array $imageRenditions = [],
        public bool $titleDerived = false,
        public ?string $shareKind = null,
        /** @var list<LinkCardContent> */
        public array $linkCards = [],
        public ?string $authorUrl = null,
        public ?string $authorAvatarUrl = null,
        public ?QuotedPostContent $quote = null,
        public ?EntryOrigin $origin = null,
        /** @var list<array<string, mixed>> */
        public array $chapters = [],
        public ?string $chaptersUrl = null,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): self
    {
        $shareKind = LineField::stringOrNull($line, 'shareKind');
        $quote = $shareKind === ShareKind::Quote->value ? self::quoteOf($line) : null;
        $chapters = LineField::objectListOrEmpty($line, 'chapters');

        return new self(
            feedUrl: LineField::string($line, 'feedUrl'),
            guid: LineField::string($line, 'guid'),
            guidHash: LineField::string($line, 'guidHash'),
            url: LineField::stringOrNull($line, 'url'),
            title: LineField::string($line, 'title'),
            author: LineField::stringOrNull($line, 'author'),
            summary: LineField::stringOrNull($line, 'summary'),
            contentHtml: LineField::stringOrNull($line, 'contentHtml'),
            imageUrl: LineField::stringOrNull($line, 'imageUrl'),
            imageWidth: LineField::intOrNull($line, 'imageWidth'),
            imageHeight: LineField::intOrNull($line, 'imageHeight'),
            publishedAt: LineField::dateOrNull($line, 'publishedAt'),
            createdAt: LineField::date($line, 'createdAt'),
            effectiveDate: LineField::date($line, 'effectiveDate'),
            media: LineField::objectListOrEmpty($line, 'media'),
            attachments: LineField::objectListOrEmpty($line, 'attachments'),
            discussionUrl: LineField::stringOrNull($line, 'discussionUrl'),
            commentsFeedUrl: LineField::stringOrNull($line, 'commentsFeedUrl'),
            commentsLoad: LineField::stringOrNull($line, 'commentsLoad'),
            imageRenditions: LineField::objectListOrEmpty($line, 'imageRenditions'),
            titleDerived: LineFieldWithDefault::bool($line, 'titleDerived', false),
            shareKind: self::shareKindOf($shareKind, $quote),
            linkCards: self::linkCardsOf($line),
            authorUrl: self::authorUrlOf($line, 'authorUrl'),
            authorAvatarUrl: self::authorUrlOf($line, 'authorAvatarUrl'),
            quote: $quote,
            origin: self::originOf($line),
            chapters: $chapters,
            chaptersUrl: $chapters === [] ? self::chaptersUrlOf($line) : null,
        );
    }

    public static function of(Entry $entry, string $feedUrl, EntryCompanionsModel $companions): self
    {
        $discussion = $entry->getDiscussion();

        return new self(
            feedUrl: $feedUrl,
            guid: $entry->getGuid(),
            guidHash: $entry->getGuidHash(),
            url: $entry->getUrl(),
            title: $entry->getTitle(),
            author: $entry->getAuthor(),
            summary: $entry->getSummary(),
            contentHtml: $entry->getContentHtml(),
            imageUrl: $entry->getImageUrl(),
            imageWidth: $entry->getImageWidth(),
            imageHeight: $entry->getImageHeight(),
            publishedAt: $entry->getPublishedAt(),
            createdAt: $entry->getCreatedAt(),
            effectiveDate: $entry->getEffectiveDate(),
            media: EntryMedia::toJsonList($entry->getMedia()),
            attachments: EntryMedia::toJsonList($entry->getAttachments()),
            discussionUrl: $discussion->url,
            commentsFeedUrl: $discussion->commentsFeedUrl,
            commentsLoad: $discussion->commentsLoad?->value,
            imageRenditions: ImageRendition::toJsonList($entry->getImage()->getRenditions()),
            titleDerived: $entry->isTitleDerived(),
            shareKind: $entry->getShareKind()?->value,
            linkCards: $companions->linkCards,
            authorUrl: $entry->getAuthorUrl(),
            authorAvatarUrl: $entry->getAuthorAvatarUrl(),
            quote: $entry->getQuotedPost(),
            origin: $companions->origin,
            chapters: EntryMedia::toJsonList($entry->getChapters()),
            chaptersUrl: $entry->getChaptersUrl(),
        );
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_ENTRY,
            'feedUrl' => $this->feedUrl,
            'guid' => $this->guid,
            'guidHash' => $this->guidHash,
            'url' => $this->url,
            'discussionUrl' => $this->discussionUrl,
            'commentsFeedUrl' => $this->commentsFeedUrl,
            'commentsLoad' => $this->commentsLoad,
            'title' => $this->title,
            'titleDerived' => $this->titleDerived,
            'shareKind' => $this->shareKind,
            'quote' => self::quoteFields($this->quote),
            'author' => $this->author,
            'authorUrl' => $this->authorUrl,
            'authorAvatarUrl' => $this->authorAvatarUrl,
            'summary' => $this->summary,
            'contentHtml' => $this->contentHtml,
            'imageUrl' => $this->imageUrl,
            'imageWidth' => $this->imageWidth,
            'imageHeight' => $this->imageHeight,
            'imageRenditions' => $this->imageRenditions,
            'media' => $this->media,
            'attachments' => $this->attachments,
            'chapters' => $this->chapters,
            'chaptersUrl' => $this->chaptersUrl,
            'linkCards' => array_map(static fn (LinkCardContent $card): array => $card->toStored(), $this->linkCards),
            'origin' => $this->origin === null
                ? null
                : ['title' => $this->origin->title, 'faviconUrl' => $this->origin->faviconUrl],
            'publishedAt' => BackupLineFormat::dateOrNull($this->publishedAt),
            'createdAt' => BackupLineFormat::date($this->createdAt),
            'effectiveDate' => BackupLineFormat::date($this->effectiveDate),
        ]);
    }

    /** @return array<string, mixed>|null */
    private static function quoteFields(?QuotedPostContent $quote): ?array
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

    private static function shareKindOf(?string $shareKind, ?QuotedPostContent $quote): ?string
    {
        return $quote === null && $shareKind === ShareKind::Quote->value ? ShareKind::Link->value : $shareKind;
    }

    /**
     * The file is user-supplied, so a quote passes the gate every made quote passes.
     *
     * @param array<string, mixed> $line
     */
    private static function quoteOf(array $line): ?QuotedPostContent
    {
        $fields = LineField::objectOrNull($line, 'quote');
        if ($fields === null) {
            return null;
        }
        $nested = LineField::objectOrNull($fields, 'nestedQuote');
        $card = LineField::objectOrNull($fields, 'linkCard');

        return StorableQuotedPost::of(new QuotedPostContent(
            LineField::string($fields, 'url'),
            LineField::string($fields, 'authorName'),
            LineField::string($fields, 'authorHandle'),
            LineField::stringOrNull($fields, 'authorAvatarUrl'),
            LineField::dateOrNull($fields, 'postedAt'),
            LineField::string($fields, 'text'),
            self::mediaOf($fields),
            $nested === null ? null : new QuotedPostReference(
                LineField::string($nested, 'url'),
                LineField::stringOrNull($nested, 'handle'),
            ),
            $card === null ? null : self::linkCardFrom($card),
        ));
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return list<EntryMedium>
     */
    private static function mediaOf(array $fields): array
    {
        $stored = array_filter(LineField::objectListOrEmpty($fields, 'media'), EntryMedium::isComplete(...));

        return array_values(array_map(EntryMedium::fromStored(...), $stored));
    }

    /** @param array<string, mixed> $line */
    private static function authorUrlOf(array $line, string $key): ?string
    {
        return AbsoluteHttpUrl::orNullWithin(LineField::stringOrNull($line, $key), EntryAuthor::URL_MAX_LENGTH);
    }

    /** @param array<string, mixed> $line */
    private static function chaptersUrlOf(array $line): ?string
    {
        $url = LineField::stringOrNull($line, 'chaptersUrl');

        return AbsoluteHttpUrl::orNullWithin($url, EntryChapters::URL_MAX_LENGTH);
    }

    /** @param array<string, mixed> $line */
    private static function originOf(array $line): ?EntryOrigin
    {
        $fields = LineField::objectOrNull($line, 'origin');
        if (null === $fields) {
            return null;
        }
        $title = LineField::stringOrNull($fields, 'title');

        return new EntryOrigin(
            null === $title ? null : mb_substr($title, 0, SavedEntryOrigin::TITLE_MAX),
            self::authorUrlOf($fields, 'faviconUrl'),
        );
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return list<LinkCardContent>
     */
    private static function linkCardsOf(array $line): array
    {
        $cards = array_map(self::linkCardOf(...), LineField::objectListOrEmpty($line, 'linkCards'));

        return array_values(array_filter($cards));
    }

    /**
     * The file is user-supplied, so each card passes the gate every made card passes.
     *
     * @param array<string, mixed> $card
     */
    private static function linkCardOf(array $card): ?LinkCardContent
    {
        return StorableLinkCard::of(self::linkCardFrom($card));
    }

    /** @param array<string, mixed> $card */
    private static function linkCardFrom(array $card): LinkCardContent
    {
        return new LinkCardContent(
            LineField::string($card, 'url'),
            LineField::string($card, 'title'),
            LineField::stringOrNull($card, 'description'),
            LineField::stringOrNull($card, 'imageUrl'),
            LineField::string($card, 'siteName'),
        );
    }
}
