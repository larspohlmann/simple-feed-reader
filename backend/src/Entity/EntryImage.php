<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An entry's lead image: the URL, the dimensions, and its verification state.
 *
 * Embedded into Entry rather than three of its own scalar columns — these
 * values are stamped and read together and mean nothing apart. The image is
 * stored optimistically at ingest (checkedAt null = pending); a bounded
 * background step then downloads it, records the measured dimensions and
 * stamps checkedAt, or drops an unreachable/beacon image. The column names are
 * unprefixed and stated explicitly, so the table is unchanged.
 */
#[ORM\Embeddable]
class EntryImage
{
    #[ORM\Column(name: 'image_url', length: 2048, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(name: 'image_width', nullable: true)]
    private ?int $width = null;

    #[ORM\Column(name: 'image_height', nullable: true)]
    private ?int $height = null;

    /** Null marks an image awaiting background verification; a value marks it settled. */
    #[ORM\Column(name: 'image_checked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    #[ORM\Column(name: 'image_verify_attempts', nullable: true)]
    private ?int $verifyAttempts = null;

    public function storePending(?string $url, ?int $width, ?int $height): void
    {
        $this->url = $url;
        $this->width = $width;
        $this->height = $height;
        $this->checkedAt = null;
        $this->verifyAttempts = null;
    }

    public function storeVerified(?string $url, ?int $width, ?int $height, \DateTimeImmutable $checkedAt): void
    {
        $this->url = $url;
        $this->width = $width;
        $this->height = $height;
        $this->checkedAt = $checkedAt;
        $this->verifyAttempts = null;
    }

    public function recordMeasurement(int $width, int $height, \DateTimeImmutable $checkedAt): void
    {
        $this->width = $width;
        $this->height = $height;
        $this->checkedAt = $checkedAt;
    }

    public function drop(): void
    {
        $this->url = null;
        $this->width = null;
        $this->height = null;
    }

    public function recordFailedProbe(): void
    {
        $this->verifyAttempts = ($this->verifyAttempts ?? 0) + 1;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function getCheckedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function getVerifyAttempts(): int
    {
        return $this->verifyAttempts ?? 0;
    }
}
