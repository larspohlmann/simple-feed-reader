<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An entry's lead image: the URL, the dimensions, its verification state and the renditions of the same picture.
 * Embedded rather than scalar columns — these values are stamped and read together and mean nothing apart.
 */
#[ORM\Embeddable]
final class EntryImage
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

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(name: 'image_renditions', type: Types::JSON, nullable: true)]
    private ?array $renditions = null;

    public function storePending(?string $url, ?int $width, ?int $height): void
    {
        $this->url = $url;
        $this->width = $width;
        $this->height = $height;
        $this->checkedAt = null;
        $this->verifyAttempts = $url === null ? null : 0;
        $this->renditions = null;
    }

    /**
     * The renditions of the stored picture; storePending() and drop() clear them, so they never outlive their URL.
     *
     * @param list<ImageRendition> $renditions
     */
    public function storeRenditions(array $renditions): void
    {
        $this->renditions = StoredList::orNull(ImageRendition::toJsonList($renditions));
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
        $this->renditions = null;
    }

    /** The host or this fetcher's policy refused the image; a browser may still render it, so it is kept as-is. */
    public function keepUnmeasured(\DateTimeImmutable $checkedAt): void
    {
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

    /** @return list<ImageRendition> */
    public function getRenditions(): array
    {
        return StoredList::read($this->renditions, ImageRendition::isComplete(...), ImageRendition::fromStored(...));
    }

    /**
     * The renditions a client may choose from, which must reach the lead image: a browser given a srcset never
     * loads its src, so a ladder that cannot is served only once the lead image's width is known to top it.
     *
     * @return list<ImageRendition>
     */
    public function servedRenditions(): array
    {
        $renditions = $this->getRenditions();
        if ($renditions === [] || $this->isAmong($renditions)) {
            return $renditions;
        }
        if ($this->url === null || $this->width === null) {
            return [];
        }
        if ($this->width <= max(array_map(static fn (ImageRendition $rung): int => $rung->width, $renditions))) {
            return $renditions;
        }

        return ImageRendition::ladder([...$renditions, new ImageRendition($this->url, $this->width)]);
    }

    /** @param list<ImageRendition> $renditions */
    private function isAmong(array $renditions): bool
    {
        return array_any($renditions, fn (ImageRendition $rung): bool => $rung->url === $this->url);
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
