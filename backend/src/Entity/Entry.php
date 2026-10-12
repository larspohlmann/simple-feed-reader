<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ShareKind;
use App\Repository\EntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntryRepository::class)]
#[ORM\Table(name: 'entry')]
#[ORM\UniqueConstraint(name: 'uniq_entry_feed_guid', columns: ['feed_id', 'guid_hash'])]
#[ORM\Index(name: 'idx_entry_effective', columns: ['effective_date', 'id'])]
#[ORM\Index(name: 'idx_entry_feed_effective', columns: ['feed_id', 'effective_date'])]
#[ORM\Index(name: 'idx_entry_feed_created', columns: ['feed_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_entry_url_hash', columns: ['url_hash', 'id'])]
#[ORM\Index(name: 'idx_entry_image_verify', columns: ['image_verify_attempts', 'id'])]
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

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contentHtml = null;

    #[ORM\Embedded(class: EntryImage::class, columnPrefix: false)]
    private EntryImage $image;

    #[ORM\Embedded(class: EntryMedia::class, columnPrefix: false)]
    private EntryMedia $mediaSet;

    #[ORM\Embedded(class: EntryDiscussion::class, columnPrefix: false)]
    private EntryDiscussion $discussion;

    #[ORM\Column(length: 8, nullable: true, enumType: ShareKind::class)]
    private ?ShareKind $shareKind = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * The list-sort instant, decided at ingest by EntryEffectiveDate from the fetch history and never recomputed here.
     * Materialized so idx_entry_effective serves the sort; the column default exists only for SQLite's migration.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, options: ['default' => '1970-01-01 00:00:00'])]
    private \DateTimeImmutable $effectiveDate;

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
        $this->headline = new EntryHeadline();
        $this->headline->store(Headline::fromFeed($title));
        $this->createdAt = $createdAt;
        $this->effectiveDate = $effectiveDate;
        $this->image = new EntryImage();
        $this->mediaSet = new EntryMedia();
        $this->author = new EntryAuthor();
        $this->discussion = new EntryDiscussion();
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

    public function setAuthor(?string $author): void
    {
        $this->author->storeName($author);
    }

    public function getAuthorUrl(): ?string
    {
        return $this->author->getProfileUrl();
    }

    public function setAuthorUrl(?string $authorUrl): void
    {
        $this->author->storeProfileUrl($authorUrl);
    }

    public function getAuthorAvatarUrl(): ?string
    {
        return $this->author->getAvatarUrl();
    }

    public function setAuthorAvatarUrl(?string $authorAvatarUrl): void
    {
        $this->author->storeAvatarUrl($authorAvatarUrl);
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): void
    {
        $this->summary = $summary;
    }

    public function getContentHtml(): ?string
    {
        return $this->contentHtml;
    }

    public function setContentHtml(?string $contentHtml): void
    {
        $this->contentHtml = $contentHtml;
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

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): void
    {
        $this->publishedAt = $publishedAt;
    }

    public function getDiscussion(): Discussion
    {
        return $this->discussion->read();
    }

    public function setDiscussion(Discussion $discussion): void
    {
        $this->discussion->store($discussion);
    }

    public function getShareKind(): ?ShareKind
    {
        return $this->shareKind;
    }

    public function setShareKind(?ShareKind $shareKind): void
    {
        $this->shareKind = $shareKind;
    }

    public function bodyStandsInForSummary(): bool
    {
        return !$this->headline->read()->isUntitledPost() && $this->shareKind === null;
    }

    public function getEffectiveDate(): \DateTimeImmutable
    {
        return $this->effectiveDate;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
