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

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(length: 2048)]
    private string $url;

    #[ORM\Column(length: 500)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column(name: 'image_url', length: 2048, nullable: true)]
    private ?string $imageUrl;

    #[ORM\Column(name: 'site_name', length: 255)]
    private string $siteName;

    public function __construct(Entry $entry, int $position, LinkCardContent $content)
    {
        $this->entry = $entry;
        $this->position = $position;
        $this->url = $content->url;
        $this->title = $content->title;
        $this->description = $content->description;
        $this->imageUrl = $content->imageUrl;
        $this->siteName = $content->siteName;
    }

    public function getEntryId(): int
    {
        return $this->entry->requireId();
    }

    public function getContent(): LinkCardContent
    {
        return new LinkCardContent($this->url, $this->title, $this->description, $this->imageUrl, $this->siteName);
    }
}
