<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The entry↔category link, a promoted many-to-many join so it can carry the
 * feed-declared order and the label this feed used for this entry. No collection
 * lives on Entry (which is at its field ceiling); the link is read by its own
 * query.
 */
#[ORM\Entity]
#[ORM\Table(name: 'entry_category')]
class EntryCategory
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(name: 'entry_id', onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(name: 'category_id')]
    private Category $category;

    #[ORM\Column(type: 'smallint')]
    private int $position;

    #[ORM\Column(length: 128)]
    private string $label;

    public function __construct(Entry $entry, Category $category, int $position, string $label)
    {
        $this->entry = $entry;
        $this->category = $category;
        $this->position = $position;
        $this->label = $label;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getLabel(): string
    {
        return $this->label;
    }
}
