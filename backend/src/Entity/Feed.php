<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FeedStatus;
use App\Repository\FeedRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FeedRepository::class)]
#[ORM\Table(name: 'feed')]
#[ORM\UniqueConstraint(name: 'uniq_feed_url', columns: ['url'])]
class Feed
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 750)]
    private string $url;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $siteUrl = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $faviconUrl = null;

    #[ORM\Column(length: 20, enumType: FeedStatus::class)]
    private FeedStatus $status = FeedStatus::Active;

    /**
     * How this feed's body is turned into entries: SourceFormat::XML (RSS/Atom
     * via FeedParser) or SourceFormat::SCRAPED (HTML listing via
     * HtmlItemExtractor). Open string matching FeedCandidate::$format — see
     * App\Enum\SourceFormat for why this is not a backed enum. The default
     * stays a literal so the ORM attribute and column stay self-describing.
     */
    #[ORM\Column(length: 20, options: ['default' => 'xml'])]
    private string $sourceFormat = 'xml';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextFetchAt = null;

    #[ORM\Column]
    private int $fetchIntervalMinutes = 60;

    #[ORM\Column]
    private int $consecutiveFailures = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastErrorMessage = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $etag = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastModified = null;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getFaviconUrl(): ?string
    {
        return $this->faviconUrl;
    }

    public function setFaviconUrl(?string $faviconUrl): void
    {
        $this->faviconUrl = $faviconUrl;
    }

    public function getStatus(): FeedStatus
    {
        return $this->status;
    }

    public function setStatus(FeedStatus $status): void
    {
        $this->status = $status;
    }

    public function getSourceFormat(): string
    {
        return $this->sourceFormat;
    }

    public function setSourceFormat(string $sourceFormat): void
    {
        $this->sourceFormat = $sourceFormat;
    }

    public function getLastFetchedAt(): ?\DateTimeImmutable
    {
        return $this->lastFetchedAt;
    }

    public function setLastFetchedAt(?\DateTimeImmutable $lastFetchedAt): void
    {
        $this->lastFetchedAt = $lastFetchedAt;
    }

    public function getNextFetchAt(): ?\DateTimeImmutable
    {
        return $this->nextFetchAt;
    }

    public function setNextFetchAt(?\DateTimeImmutable $nextFetchAt): void
    {
        $this->nextFetchAt = $nextFetchAt;
    }

    public function getFetchIntervalMinutes(): int
    {
        return $this->fetchIntervalMinutes;
    }

    public function setFetchIntervalMinutes(int $minutes): void
    {
        $this->fetchIntervalMinutes = $minutes;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function setConsecutiveFailures(int $consecutiveFailures): void
    {
        $this->consecutiveFailures = $consecutiveFailures;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function setLastErrorMessage(?string $lastErrorMessage): void
    {
        $this->lastErrorMessage = $lastErrorMessage;
    }

    public function getEtag(): ?string
    {
        return $this->etag;
    }

    public function setEtag(?string $etag): void
    {
        $this->etag = $etag;
    }

    public function getLastModified(): ?string
    {
        return $this->lastModified;
    }

    public function setLastModified(?string $lastModified): void
    {
        $this->lastModified = $lastModified;
    }
}
