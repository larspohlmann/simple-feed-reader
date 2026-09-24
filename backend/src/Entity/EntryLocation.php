<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An entry's article URL and its stable-identity hash, embedded as one field
 * rather than two of Entry's own columns — the same move EntryImage and
 * EntryMedia make to hold Entry under PHPMD's field ceiling: the two are
 * stamped together at ingest and read together by the dedup.
 */
#[ORM\Embeddable]
class EntryLocation
{
    #[ORM\Column(name: 'url', length: 2048, nullable: true)]
    private ?string $url = null;

    /**
     * sha256 of the normalized article URL, the stable identity a feed keeps
     * across a volatile GUID (BBC's revision counter). Null when the item has
     * no URL — those still dedupe on guidHash — and on every row created before
     * #484 added the column; the ingest dedup treats a null as "no URL match".
     */
    #[ORM\Column(name: 'url_hash', length: 64, nullable: true)]
    private ?string $urlHash = null;

    public function store(?string $url, ?string $urlHash): void
    {
        $this->url = $url;
        $this->urlHash = $urlHash;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getUrlHash(): ?string
    {
        return $this->urlHash;
    }
}
