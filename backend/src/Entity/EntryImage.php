<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An entry's lead image: the URL, the dimensions, and its verification state.
 * Embedded rather than three scalar columns — these values are stamped and
 * read together and mean nothing apart.
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

    /** When this instance judged the image; null = never judged. */
    #[ORM\Column(name: 'image_checked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    /** Non-null marks the image pending verification; every settled state resets it to null. */
    #[ORM\Column(name: 'image_verify_attempts', nullable: true)]
    private ?int $verifyAttempts = null;

    public function storePending(?string $url, ?int $width, ?int $height): void
    {
        $this->url = $url;
        $this->width = $width;
        $this->height = $height;
        $this->checkedAt = null;
        $this->verifyAttempts = $url === null ? null : 0;
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
        $this->verifyAttempts = null;
    }

    /** The checkedAt stays behind as a tombstone, so a refresh never restores a rejected image. */
    public function drop(\DateTimeImmutable $checkedAt): void
    {
        $this->url = null;
        $this->width = null;
        $this->height = null;
        $this->checkedAt = $checkedAt;
        $this->verifyAttempts = null;
    }

    public function recordFailedProbe(): void
    {
        $this->verifyAttempts = ($this->verifyAttempts ?? 0) + 1;
    }

    public function isMissing(): bool
    {
        return $this->url === null && $this->checkedAt === null;
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
