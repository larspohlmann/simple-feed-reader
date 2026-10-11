<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ShareKind;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final class EntryLocation
{
    private const int URL_MAX = 2048;

    #[ORM\Column(name: 'url', length: self::URL_MAX, nullable: true)]
    private ?string $url = null;

    /** Null for a URL-less entry or a row older than this column; the ingest dedup treats both as "no URL match". */
    #[ORM\Column(name: 'url_hash', length: 64, nullable: true)]
    private ?string $urlHash = null;

    #[ORM\Column(name: 'share_kind', length: 8, nullable: true, enumType: ShareKind::class)]
    private ?ShareKind $shareKind = null;

    #[ORM\Column(name: 'share_url', length: self::URL_MAX, nullable: true)]
    private ?string $shareUrl = null;

    public function store(?string $url, ?string $urlHash): void
    {
        $this->url = $url;
        $this->urlHash = $urlHash;
    }

    public function storeShare(Share $share): void
    {
        $this->shareKind = $share->kind;
        $this->shareUrl = $share->url === null || \strlen($share->url) > self::URL_MAX ? null : $share->url;
    }

    public function readShare(): Share
    {
        return Share::of($this->shareKind, $this->shareUrl);
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
