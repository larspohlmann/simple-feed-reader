<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final class EntryHeadline
{
    #[ORM\Column(name: 'title', length: 1024)]
    private string $title = '';

    #[ORM\Column(name: 'title_derived', options: ['default' => false])]
    private bool $derived = false;

    public function store(string $title): void
    {
        $this->title = $title;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function isDerived(): bool
    {
        return $this->derived;
    }

    public function markDerived(): void
    {
        $this->derived = true;
    }
}
