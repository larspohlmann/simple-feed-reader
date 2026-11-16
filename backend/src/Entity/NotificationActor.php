<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final readonly class NotificationActor
{
    public function __construct(
        #[ORM\Column(length: 255)]
        public string $handle,
        #[ORM\Column(length: 255, nullable: true)]
        public ?string $displayName,
        #[ORM\Column(length: 2048, nullable: true)]
        public ?string $avatarUrl,
        #[ORM\Column(length: 2048)]
        public string $profileUrl,
    ) {
    }
}
