<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ShareKind;
use App\Repository\EntryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntryRepository::class)]
#[ORM\Table(name: 'entry')]
#[ORM\UniqueConstraint(name: 'uniq_entry_feed_guid', columns: ['feed_id', 'guid_hash'])]
#[ORM\Index(name: 'idx_entry_effective', columns: ['effective_date', 'id'])]
#[ORM\Index(name: 'idx_entry_feed_effective_hash', columns: ['feed_id', 'effective_date', 'url_hash'])]
#[ORM\Index(name: 'idx_entry_feed_created', columns: ['feed_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_entry_url_hash', columns: ['url_hash', 'id'])]
#[ORM\Index(name: 'idx_entry_image_verify', columns: ['image_verify_attempts', 'id'])]
#[ORM\Index(name: 'idx_entry_image_url_hash', columns: ['image_url_hash'])]
#[ORM\Index(name: 'idx_entry_canonical_item', columns: ['canonical_item_id', 'id'])]
final class Entry
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Feed::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Feed $feed;

    #[ORM\Embedded(class: EntryGuid::class, columnPrefix: false)]
    private EntryGuid $guid;

    #[ORM\Embedded(class: EntryLocation::class, columnPrefix: false)]
    private EntryLocation $location;

    #[ORM\Embedded(class: EntryHeadline::class, columnPrefix: false)]
    private EntryHeadline $headline;

    #[ORM\Embedded(class: EntryAuthor::class, columnPrefix: false)]
    private EntryAuthor $author;

    #[ORM\Embedded(class: EntryText::class, columnPrefix: false)]
    private EntryText $text;

    #[ORM\Embedded(class: EntryImage::class, columnPrefix: false)]
    private EntryImage $image;

    #[ORM\Embedded(class: EntryMedia::class, columnPrefix: false)]
    private EntryMedia $mediaSet;

    #[ORM\Embedded(class: EntryDiscussion::class, columnPrefix: false)]
    private EntryDiscussion $discussion;

    #[ORM\ManyToOne(targetEntity: CanonicalItem::class)]
    #[ORM\JoinColumn(name: 'canonical_item_id', nullable: true, onDelete: 'SET NULL')]
    private ?CanonicalItem $canonicalItem = null;

    #[ORM\Embedded(class: EntrySharedContent::class, columnPrefix: false)]
    private EntrySharedContent $share;

    #[ORM\ManyToOne(targetEntity: SharedLink::class)]
    #[ORM\JoinColumn(name: 'quoted_shared_link_id', nullable: true)]
    private ?SharedLink $quotedLink = null;

    #[ORM\Embedded(class: EntryDates::class, columnPrefix: false)]
    private EntryDates $dates;

    public function __construct(
        Feed $feed,
        string $guid,
        ?string $url,
        string $title,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $effectiveDate,
        ?string $urlHash = null,
    ) {
        $this->feed = $feed;
        $this->guid = new EntryGuid($guid);
        $this->location = new EntryLocation();
        $this->location->store($url, $urlHash);
        $this->text = new EntryText();
        $this->headline = new EntryHeadline();
        $this->headline->store(Headline::fromFeed($title));
        $this->dates = new EntryDates($createdAt, $effectiveDate);
        $this->image = new EntryImage();
        $this->mediaSet = new EntryMedia();
        $this->author = new EntryAuthor();
        $this->discussion = new EntryDiscussion();
        $this->share = new EntrySharedContent();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFeed(): Feed
    {
        return $this->feed;
    }

    public function getGuid(): string
    {
        return $this->guid->getValue();
    }

    public function getGuidHash(): string
    {
        return $this->guid->getHash();
    }

    public function getUrlHash(): ?string
    {
        return $this->location->getUrlHash();
    }

    public function getUrl(): ?string
    {
        return $this->location->getUrl();
    }

    public function getTitle(): string
    {
        return $this->headline->read()->text;
    }

    public function isTitleDerived(): bool
    {
        return $this->headline->read()->derived;
    }

    public function setHeadline(Headline $headline): void
    {
        $this->headline->store($headline);
    }

    public function getAuthor(): ?string
    {
        return $this->author->getName();
    }

    public function setAuthor(EntryAuthor $author): void
    {
        $this->author = $author;
    }

    public function getAuthorUrl(): ?string
    {
        return $this->author->getProfileUrl();
    }

    public function getAuthorAvatarUrl(): ?string
    {
        return $this->author->getAvatarUrl();
    }

    public function getText(): EntryText
    {
        return $this->text;
    }

    public function recordText(EntryText $text): void
    {
        $this->text = $text;
    }

    public function getImageUrl(): ?string
    {
        return $this->image->getUrl();
    }

    public function getImageWidth(): ?int
    {
        return $this->image->getWidth();
    }

    public function getImageHeight(): ?int
    {
        return $this->image->getHeight();
    }

    public function getImage(): EntryImage
    {
        return $this->image;
    }

    /** Drops the image and removes it from media[], leaving other media untouched. */
    public function dropImage(\DateTimeImmutable $checkedAt): void
    {
        $url = $this->image->getUrl();
        if ($url !== null) {
            $this->mediaSet->removeUrl($url);
        }
        $this->image->drop($checkedAt);
    }

    /** @return list<EntryMedium> */
    public function getMedia(): array
    {
        return $this->mediaSet->getMedia();
    }

    /** @return list<EntryAttachment> */
    public function getAttachments(): array
    {
        return $this->mediaSet->getAttachments();
    }

    /**
     * @param list<EntryMedium>     $media
     * @param list<EntryAttachment> $attachments
     */
    public function setMedia(array $media, array $attachments): void
    {
        $this->mediaSet->set($media, $attachments);
    }

    public function putMediumFirst(EntryMedium $medium): void
    {
        $this->mediaSet->putFirst($medium);
    }

    /** @return list<EntryChapter> */
    public function getChapters(): array
    {
        return $this->mediaSet->getChapters();
    }

    public function getChaptersUrl(): ?string
    {
        return $this->mediaSet->getChaptersUrl();
    }

    /** @param list<EntryChapter> $chapters */
    public function declareChapters(array $chapters, ?string $url): void
    {
        $this->mediaSet->declareChapters($chapters, $url);
    }

    /** @param list<EntryChapter> $chapters */
    public function fillChapters(array $chapters): void
    {
        $this->mediaSet->fillChapters($chapters);
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->dates->getPublishedAt();
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): void
    {
        $this->dates->setPublishedAt($publishedAt);
    }

    public function getDiscussion(): Discussion
    {
        return $this->discussion->read();
    }

    public function isDiscussionThread(): bool
    {
        return $this->getUrl() === null && $this->getDiscussion()->url !== null;
    }

    public function setDiscussion(Discussion $discussion): void
    {
        $this->discussion->store($discussion);
    }

    public function getCanonicalItem(): ?CanonicalItem
    {
        return $this->canonicalItem;
    }

    public function linkTo(CanonicalItem $item): void
    {
        $item->recordCommentCount($this->discussion->getCommentCount());
        $item->recordSelfReplyCount($this->discussion->getSelfReplyCount());
        $this->discussion->clearCounts();
        $this->canonicalItem = $item;
    }

    /** The item's counts once the entry is linked to one, the entry's own before. */
    public function discussionCounts(): DiscussionCountStoreInterface
    {
        return $this->canonicalItem ?? $this->discussion;
    }

    public function getShareKind(): ?ShareKind
    {
        return $this->share->kind();
    }

    public function setShareKind(?ShareKind $shareKind): void
    {
        $this->share->classify($shareKind);
        if ($shareKind !== ShareKind::Quote) {
            $this->quotedLink = null;
        }
    }

    public function getQuotedPost(): ?QuotedPostContent
    {
        return $this->share->quotedPost()?->withLinkCard($this->quotedLink?->preview());
    }

    public function shareQuotedPost(QuotedPostContent $quote, ?SharedLink $link): void
    {
        $this->share->quote($quote);
        $this->quotedLink = $link;
    }

    public function bodyStandsInForSummary(): bool
    {
        return !$this->headline->read()->isUntitledPost() && $this->share->kind() === null;
    }

    public function getEffectiveDate(): \DateTimeImmutable
    {
        return $this->dates->getEffectiveDate();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->dates->getCreatedAt();
    }
}
