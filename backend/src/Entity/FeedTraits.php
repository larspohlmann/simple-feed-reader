<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FeedLanguage;
use App\Enum\FeedPlatform;
use Doctrine\ORM\Mapping as ORM;

/** What the parser learns about a feed from its body on every successful parse. */
#[ORM\Embeddable]
final readonly class FeedTraits
{
    public function __construct(
        #[ORM\Column(length: 16, nullable: true, enumType: FeedPlatform::class)]
        private ?FeedPlatform $platform = null,
        #[ORM\Column(length: 2, nullable: true, enumType: FeedLanguage::class)]
        private ?FeedLanguage $language = null,
    ) {
    }

    public function getPlatform(): ?FeedPlatform
    {
        return $this->platform;
    }

    public function getLanguage(): ?FeedLanguage
    {
        return $this->language;
    }

    #[\NoDiscard]
    public function withPlatform(?FeedPlatform $platform): self
    {
        return clone($this, ['platform' => $platform]);
    }

    #[\NoDiscard]
    public function withLanguage(?FeedLanguage $language): self
    {
        return clone($this, ['language' => $language]);
    }
}
