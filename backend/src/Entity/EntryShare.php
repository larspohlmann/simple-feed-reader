<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ShareKind;
use Doctrine\ORM\Mapping as ORM;

/** What an entry shares: its kind, and for a quote the post it quotes. */
#[ORM\Embeddable]
final class EntryShare
{
    #[ORM\Column(name: 'share_kind', length: 8, nullable: true, enumType: ShareKind::class)]
    private ?ShareKind $kind = null;

    #[ORM\Embedded(class: EntryQuotedPost::class, columnPrefix: false)]
    private EntryQuotedPost $quotedPost;

    public function __construct()
    {
        $this->quotedPost = new EntryQuotedPost();
    }

    public function kind(): ?ShareKind
    {
        return $this->kind;
    }

    public function classify(?ShareKind $kind): void
    {
        if ($kind !== ShareKind::Quote) {
            $this->quotedPost = new EntryQuotedPost();
        }
        $this->kind = $kind;
    }

    public function quotedPost(): ?QuotedPostContent
    {
        return $this->quotedPost->read();
    }

    public function quote(QuotedPostContent $quote): void
    {
        $this->quotedPost->store($quote);
        $this->kind = ShareKind::Quote;
    }
}
