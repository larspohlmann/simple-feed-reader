<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** The words of an entry: the short summary and the full body the reader shows. */
#[ORM\Embeddable]
final readonly class EntryText
{
    public function __construct(
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $summary = null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $contentHtml = null,
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
}
