<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FeedLanguage;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** The words of an entry: the short summary, the full body the reader shows, and the language they are in. */
#[ORM\Embeddable]
final readonly class EntryText
{
    public function __construct(
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $summary = null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $contentHtml = null,
        #[ORM\Column(length: 2, nullable: true, enumType: FeedLanguage::class)]
        private ?FeedLanguage $language = null,
    ) {
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getContentHtml(): ?string
    {
        return $this->contentHtml;
    }

    public function getLanguage(): ?FeedLanguage
    {
        return $this->language;
    }

    #[\NoDiscard]
    public function withSummary(?string $summary): self
    {
        return clone($this, ['summary' => $summary]);
    }

    #[\NoDiscard]
    public function withContentHtml(?string $contentHtml): self
    {
        return clone($this, ['contentHtml' => $contentHtml]);
    }

    #[\NoDiscard]
    public function withLanguage(?FeedLanguage $language): self
    {
        return clone($this, ['language' => $language]);
    }
}
