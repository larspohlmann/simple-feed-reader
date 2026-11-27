<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EntryLabelRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntryLabelRepository::class)]
#[ORM\Table(name: 'entry_label')]
#[ORM\Index(name: 'idx_entry_label_entry', columns: ['entry_id'])]
final class EntryLabel
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Label::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Label $label;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Entry $entry;

    public function __construct(Label $label, Entry $entry)
    {
        $this->label = $label;
        $this->entry = $entry;
    }

    public function getLabel(): Label
    {
        return $this->label;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }
}
