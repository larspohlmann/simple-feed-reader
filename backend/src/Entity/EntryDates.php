<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final class EntryDates
{
    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * The list-sort instant, decided at ingest by EntryEffectiveDate from the fetch history and never recomputed here.
     * Materialized so idx_entry_effective serves the sort; the column default exists only for SQLite's migration.
     */
    #[ORM\Column(
        name: 'effective_date',
        type: Types::DATETIME_IMMUTABLE,
        options: ['default' => '1970-01-01 00:00:00'],
    )]
    private \DateTimeImmutable $effectiveDate;

    public function __construct(\DateTimeImmutable $createdAt, \DateTimeImmutable $effectiveDate)
    {
        $this->createdAt = $createdAt;
        $this->effectiveDate = $effectiveDate;
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

    public function getEffectiveDate(): \DateTimeImmutable
    {
        return $this->effectiveDate;
    }
}
