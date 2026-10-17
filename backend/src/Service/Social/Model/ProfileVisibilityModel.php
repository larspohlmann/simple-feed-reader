<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class ProfileVisibilityModel
{
    private function __construct(
        public ProfileVisibility $visibility,
        public ?HiddenProfileModel $hidden,
    ) {
    }

    public static function unknown(): self
    {
        return new self(ProfileVisibility::Unknown, null);
    }

    public static function publicProfile(): self
    {
        return new self(ProfileVisibility::Public, null);
    }

    public static function hiddenProfile(HiddenProfileModel $profile): self
    {
        return new self(ProfileVisibility::Hidden, $profile);
    }

    public function isUnknown(): bool
    {
        return ProfileVisibility::Unknown === $this->visibility;
    }

    public function isPublic(): bool
    {
        return ProfileVisibility::Public === $this->visibility;
    }
}
