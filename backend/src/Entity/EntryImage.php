<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An entry's lead image: the URL and the dimensions the feed declared for it.
 *
 * Embedded into Entry rather than left as three of its own scalar columns —
 * PHPMD's field-count ceiling on Entry is a proxy for a real seam: these three
 * values are stamped and read together (EntryIngestor::applyImage sets all
 * three at once, EntryJson emits all three at once) and mean nothing apart. An
 * embeddable keeps them together without the join or lifecycle a separate
 * entity would add; the column names are unprefixed and stated explicitly, so
 * the table itself is unchanged (see ProviderUsage for the same move on
 * RecommendationRun).
 */
#[ORM\Embeddable]
class EntryImage
{
    #[ORM\Column(name: 'image_url', length: 2048, nullable: true)]
    private ?string $url = null;

    /** As DECLARED by the feed. Null means unknown, not "no image". */
    #[ORM\Column(name: 'image_width', nullable: true)]
    private ?int $width = null;

    #[ORM\Column(name: 'image_height', nullable: true)]
    private ?int $height = null;

    /**
     * Sets all three values together so a rejected image never leaves a stale
     * dimension behind — the invariant the single call site (Entry::setImage)
     * relies on.
     */
    public function set(?string $url, ?int $width, ?int $height): void
    {
        $this->url = $url;
        $this->width = $width;
        $this->height = $height;
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
}
