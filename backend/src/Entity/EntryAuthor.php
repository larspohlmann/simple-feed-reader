<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final class EntryAuthor
{
    public const int URL_MAX_LENGTH = 2048;

    #[ORM\Column(name: 'author', length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(name: 'author_url', length: self::URL_MAX_LENGTH, nullable: true)]
    private ?string $profileUrl = null;

    #[ORM\Column(name: 'author_avatar_url', length: self::URL_MAX_LENGTH, nullable: true)]
    private ?string $avatarUrl = null;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getProfileUrl(): ?string
    {
        return $this->profileUrl;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function storeName(?string $name): void
    {
        $this->name = $name;
    }

    public function storeProfileUrl(?string $profileUrl): void
    {
        $this->profileUrl = $profileUrl;
    }

    public function storeAvatarUrl(?string $avatarUrl): void
    {
        $this->avatarUrl = $avatarUrl;
    }
}
