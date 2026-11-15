<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FeedLanguage;
use App\Enum\LanguageSource;
use App\Repository\SharedLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SharedLinkRepository::class)]
#[ORM\Table(name: 'shared_link')]
#[ORM\UniqueConstraint(name: 'uniq_shared_link_url_hash', columns: ['url_hash'])]
final class SharedLink
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: LinkCardContent::URL_MAX)]
    private string $url;

    #[ORM\Column(name: 'url_hash', length: 64)]
    private string $urlHash;

    #[ORM\Column(length: LinkCardContent::TITLE_MAX)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'image_url', length: LinkCardContent::URL_MAX, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(name: 'site_name', length: LinkCardContent::SITE_NAME_MAX)]
    private string $siteName;

    #[ORM\Column(length: 2, nullable: true, enumType: FeedLanguage::class)]
    private ?FeedLanguage $language = null;

    #[ORM\Column(name: 'language_source', length: 8, nullable: true, enumType: LanguageSource::class)]
    private ?LanguageSource $languageSource = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $urlHash,
        LinkCardContent $preview,
        ?LinkLanguage $language,
        \DateTimeImmutable $createdAt,
    ) {
        $this->urlHash = $urlHash;
        $this->url = $preview->url;
        $this->refresh($preview);
        if ($language !== null) {
            $this->settleLanguage($language);
        }
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUrlHash(): string
    {
        return $this->urlHash;
    }

    public function preview(): LinkCardContent
    {
        return new LinkCardContent($this->url, $this->title, $this->description, $this->imageUrl, $this->siteName);
    }

    public function getLanguage(): ?FeedLanguage
    {
        return $this->language;
    }

    public function getLanguageSource(): ?LanguageSource
    {
        return $this->languageSource;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Keeps the URL, and an image or description the new preview does not declare: a refresh never blanks a card. */
    public function refresh(LinkCardContent $preview): void
    {
        $this->title = $preview->title;
        $this->description = $preview->description ?? $this->description;
        $this->imageUrl = $preview->imageUrl ?? $this->imageUrl;
        $this->siteName = $preview->siteName;
    }

    /** A stated language is never replaced, and a guess replaces nothing set; a statement replaces a guess. */
    public function settleLanguage(LinkLanguage $language): void
    {
        $replacesAGuess = $this->languageSource === LanguageSource::Guessed
            && $language->source === LanguageSource::Stated;
        if ($this->language !== null && !$replacesAGuess) {
            return;
        }
        $this->language = $language->language;
        $this->languageSource = $language->source;
    }
}
