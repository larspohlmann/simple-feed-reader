<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class EntryLocation
{
    #[ORM\Column(name: 'url', length: 2048, nullable: true)]
    private ?string $url = null;

    /**
     * Null means either a URL-less entry or a row from before #484 added this
     * column; the ingest dedup treats both as "no URL match".
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
