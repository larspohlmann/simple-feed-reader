<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntryRepository::class)]
#[ORM\Table(name: 'entry')]
#[ORM\UniqueConstraint(name: 'uniq_entry_feed_guid', columns: ['feed_id', 'guid_hash'])]
#[ORM\Index(name: 'idx_entry_effective', columns: ['effective_date', 'id'])]
#[ORM\Index(name: 'idx_entry_feed_effective', columns: ['feed_id', 'effective_date'])]
#[ORM\Index(name: 'idx_entry_list', columns: ['created_at', 'published_at', 'id'])]
class Entry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Feed::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Feed $feed;

    #[ORM\Column(type: Types::TEXT)]
    private string $guid;

    #[ORM\Column(length: 64)]
    private string $guidHash;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $url;

    #[ORM\Column(length: 1024)]
    private string $title;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $author = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contentHtml = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $imageUrl = null;

    /** As DECLARED by the feed. Null means unknown, not "no image". */
    #[ORM\Column(nullable: true)]
    private ?int $imageWidth = null;

    #[ORM\Column(nullable: true)]
    private ?int $imageHeight = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * The list-sort instant, decided by EntryEffectiveDate at ingest and never
     * recomputed here. It used to be `publishedAt ?? createdAt`, derived in this
     * class so it could not drift; the rule now needs the fetch that stored the
     * entry and the feed's previous fetch, which an entity has no business
     * knowing. The invariant moved to one policy with its own tests (#384).
     * Materialized rather than COALESCE'd so idx_entry_effective can serve the
     * reader's sort. The column default exists only for the migration on SQLite,
     * which cannot add a NOT NULL column without one.
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
    ) {
        $this->feed = $feed;
        $this->guid = $guid;
        $this->guidHash = hash('sha256', $guid);
        $this->url = $url;
        $this->title = $title;
        $this->createdAt = $createdAt;
        $this->effectiveDate = $effectiveDate;
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
        return $this->guid;
    }

    public function getGuidHash(): string
    {
        return $this->guidHash;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(?string $author): void
    {
        $this->author = $author;
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
        return $this->imageUrl;
    }

    public function getImageWidth(): ?int
    {
        return $this->imageWidth;
    }

    public function getImageHeight(): ?int
    {
        return $this->imageHeight;
    }

    public function setImage(?string $url, ?int $width, ?int $height): void
    {
        $this->imageUrl = $url;
        $this->imageWidth = $width;
        $this->imageHeight = $height;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): void
    {
        $this->publishedAt = $publishedAt;
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
