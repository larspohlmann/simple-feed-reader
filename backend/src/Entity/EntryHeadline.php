<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final class EntryHeadline
{
    private const int TITLE_MAX = 1024;

    #[ORM\Column(name: 'title', length: self::TITLE_MAX)]
    private string $title = '';

    #[ORM\Column(name: 'title_derived', options: ['default' => false])]
    private bool $derived = false;

    public function store(Headline $headline): void
    {
        $this->title = mb_substr($headline->text, 0, self::TITLE_MAX);
        $this->derived = $headline->derived;
    }

    public function read(): Headline
    {
        return $this->derived ? Headline::derived($this->title) : Headline::fromFeed($this->title);
    }
}
