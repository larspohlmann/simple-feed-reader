<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final readonly class NotificationSubject
{
    public function __construct(
        #[ORM\Column(length: 512)]
        public string $groupKey,
        #[ORM\Column(length: 500, nullable: true)]
        public ?string $excerpt,
        #[ORM\Column(length: 2048)]
        public string $linkUrl,
    ) {
    }
}
