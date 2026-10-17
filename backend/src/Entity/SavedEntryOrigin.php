<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SavedEntryOriginRepository;
use Doctrine\ORM\Mapping as ORM;

/** The origin of an RSS article in a "Saved from feeds" feed; a row only such copies have. */
#[ORM\Entity(repositoryClass: SavedEntryOriginRepository::class)]
#[ORM\Table(name: 'saved_entry_origin')]
final class SavedEntryOrigin
{
    public const int TITLE_MAX = 512;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column(length: self::TITLE_MAX, nullable: true)]
    private ?string $title;

    #[ORM\Column(name: 'favicon_url', length: 2048, nullable: true)]
    private ?string $faviconUrl;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(Entry $entry, EntryOrigin $origin)
    {
        $this->entry = $entry;
        $this->title = $origin->title;
        $this->faviconUrl = $origin->faviconUrl;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function origin(): EntryOrigin
    {
        return new EntryOrigin($this->title, $this->faviconUrl);
    }
}
