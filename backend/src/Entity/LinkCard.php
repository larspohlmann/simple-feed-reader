<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LinkCardRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LinkCardRepository::class)]
#[ORM\Table(name: 'link_card')]
#[ORM\UniqueConstraint(name: 'uniq_link_card_entry_position', columns: ['entry_id', 'position'])]
final class LinkCard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\ManyToOne(targetEntity: ThreadPost::class)]
    #[ORM\JoinColumn(name: 'thread_post_id', nullable: true, onDelete: 'CASCADE')]
    private ?ThreadPost $threadPost;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\ManyToOne(targetEntity: SharedLink::class)]
    #[ORM\JoinColumn(name: 'shared_link_id', nullable: false)]
    private SharedLink $link;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(Entry $entry, int $position, SharedLink $link, ?ThreadPost $threadPost = null)
    {
        $this->entry = $entry;
        $this->position = $position;
        $this->link = $link;
        $this->threadPost = $threadPost;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getLink(): SharedLink
    {
        return $this->link;
    }
}
