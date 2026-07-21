<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntryRepository::class)]
#[ORM\Table(name: 'entry')]
#[ORM\UniqueConstraint(name: 'uniq_entry_feed_guid', columns: ['feed_id', 'guid_hash'])]
#[ORM\Index(name: 'idx_entry_feed_published', columns: ['feed_id', 'published_at'])]
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
    private ?string $url = null;

    #[ORM\Column(length: 1024)]
    private string $title;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $author = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contentHtml = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Feed $feed,
        string $guid,
        ?string $url,
        string $title,
        \DateTimeImmutable $createdAt,
    ) {
        $this->feed = $feed;
        $this->guid = $guid;
        $this->guidHash = hash('sha256', $guid);
        $this->url = $url;
        $this->title = $title;
        $this->createdAt = $createdAt;
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

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): void
    {
        $this->publishedAt = $publishedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
