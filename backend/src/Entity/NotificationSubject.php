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
        #[ORM\Embedded(class: NotificationContext::class, columnPrefix: 'context_')]
        public NotificationContext $context = new NotificationContext(),
    ) {
    }

    #[\NoDiscard]
    public function withContext(NotificationContext $context): self
    {
        return clone($this, ['context' => $context]);
    }

    public static function follow(string $profileUrl): self
    {
        return new self('', null, $profileUrl);
    }
}
