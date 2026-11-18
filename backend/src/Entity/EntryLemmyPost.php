<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EntryLemmyPostRepository;
use Doctrine\ORM\Mapping as ORM;

/** What only a Lemmy post carries. Owned from this side, never mapped on Entry, so no list loads it unasked. */
#[ORM\Entity(repositoryClass: EntryLemmyPostRepository::class)]
#[ORM\Table(name: 'entry_lemmy_post')]
final class EntryLemmyPost
{
    public const int IDENTITY_MAX_LENGTH = 255;
    public const int URL_MAX_LENGTH = 2048;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column(nullable: true)]
    private ?int $score = null;

    #[ORM\Column(name: 'community_identity', length: self::IDENTITY_MAX_LENGTH, nullable: true)]
    private ?string $communityIdentity = null;

    #[ORM\Column(name: 'community_url', length: self::URL_MAX_LENGTH, nullable: true)]
    private ?string $communityUrl = null;

    public function __construct(Entry $entry)
    {
        $this->entry = $entry;
    }

    public function recordScore(?int $score): void
    {
        $this->score = $score ?? $this->score;
    }

    public function placeIn(PostCommunity $community): void
    {
        $this->communityIdentity = $community->identity;
        $this->communityUrl = $community->url;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function getCommunity(): ?PostCommunity
    {
        if ($this->communityIdentity === null || $this->communityUrl === null) {
            return null;
        }

        return PostCommunity::of($this->communityIdentity, $this->communityUrl);
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }
}
