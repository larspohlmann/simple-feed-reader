<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SourceFormat;
use App\Repository\CatalogFeedRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One suggestion in the onboarding catalog. The URL is a VERIFIED direct feed
 * URL, which is what lets the subscribe path skip discovery entirely.
 *
 * The favicon bytes live on the row rather than on disk so the cache shares the
 * catalog's backup/restore unit and needs no writable var/ path on Strato. They
 * are filled exclusively by app:catalog:warm-favicons — never by a request.
 */
#[ORM\Entity(repositoryClass: CatalogFeedRepository::class)]
#[ORM\Table(name: 'catalog_feed')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_feed_url', columns: ['url'])]
class CatalogFeed
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CatalogCategory::class, inversedBy: 'feeds')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CatalogCategory $category;

    #[ORM\Column(length: 200)]
    private string $title;

    /** Same 750 limit as Feed::$url, so a catalog row can never be too long to subscribe to. */
    #[ORM\Column(length: 750)]
    private string $url;

    #[ORM\Column(length: 750, nullable: true)]
    private ?string $siteUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20, options: ['default' => SourceFormat::XML])]
    private string $sourceFormat = SourceFormat::XML;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    /**
     * A locked row is the admin's, not the catalog document's: an import will
     * neither overwrite nor delete it. This is what lets an admin add a feed the
     * shipped catalog does not carry and still run a `replace` import.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $locked = false;

    #[ORM\Column(length: 750, nullable: true)]
    private ?string $faviconSourceUrl = null;

    #[ORM\Column(type: Types::BLOB, nullable: true)]
    private mixed $faviconData = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $faviconContentType = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $faviconFetchedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $faviconFailedAt = null;

    public function __construct(CatalogCategory $category, string $title, string $url)
    {
        $this->category = $category;
        $this->title = $title;
        $this->url = $url;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): CatalogCategory
    {
        return $this->category;
    }

    public function setCategory(CatalogCategory $category): void
    {
        $this->category = $category;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getSiteUrl(): ?string
    {
        return $this->siteUrl;
    }

    public function setSiteUrl(?string $siteUrl): void
    {
        $this->siteUrl = $siteUrl;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getSourceFormat(): string
    {
        return $this->sourceFormat;
    }

    public function setSourceFormat(string $sourceFormat): void
    {
        $this->sourceFormat = $sourceFormat;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): void
    {
        $this->locked = $locked;
    }

    public function getFaviconSourceUrl(): ?string
    {
        return $this->faviconSourceUrl;
    }

    /**
     * Doctrine hands back a stream for a BLOB column; callers want bytes. Null
     * means "no icon cached", which is what makes the endpoint serve a monogram.
     */
    public function getFaviconBytes(): ?string
    {
        if (null === $this->faviconData) {
            return null;
        }
        if (\is_resource($this->faviconData)) {
            rewind($this->faviconData);

            return (string) stream_get_contents($this->faviconData);
        }

        return \is_string($this->faviconData) ? $this->faviconData : null;
    }

    public function getFaviconContentType(): ?string
    {
        return $this->faviconContentType;
    }

    public function getFaviconFetchedAt(): ?\DateTimeImmutable
    {
        return $this->faviconFetchedAt;
    }

    public function getFaviconFailedAt(): ?\DateTimeImmutable
    {
        return $this->faviconFailedAt;
    }

    public function storeFavicon(
        string $sourceUrl,
        string $bytes,
        string $contentType,
        \DateTimeImmutable $fetchedAt,
    ): void {
        $this->faviconSourceUrl = $sourceUrl;
        $this->faviconData = $bytes;
        $this->faviconContentType = $contentType;
        $this->faviconFetchedAt = $fetchedAt;
        $this->faviconFailedAt = null;
    }

    public function recordFaviconFailure(\DateTimeImmutable $failedAt): void
    {
        $this->faviconFailedAt = $failedAt;
    }
}
