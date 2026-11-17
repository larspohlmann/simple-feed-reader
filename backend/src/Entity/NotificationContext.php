<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final readonly class NotificationContext
{
    public function __construct(
        #[ORM\Column(length: 500, nullable: true)]
        public ?string $answeredExcerpt = null,
        #[ORM\Column(length: 500, nullable: true)]
        public ?string $threadTitle = null,
        #[ORM\Column(length: 255, nullable: true)]
        public ?string $threadPlace = null,
        #[ORM\Column(length: 255, nullable: true)]
        public ?string $threadBy = null,
    ) {
    }

    #[\NoDiscard]
    public function withAnsweredExcerpt(?string $answeredExcerpt): self
    {
        return clone($this, ['answeredExcerpt' => $answeredExcerpt]);
    }

    #[\NoDiscard]
    public function withThread(?string $threadTitle, ?string $threadBy): self
    {
        return clone($this, ['threadTitle' => $threadTitle, 'threadBy' => $threadBy]);
    }
}
