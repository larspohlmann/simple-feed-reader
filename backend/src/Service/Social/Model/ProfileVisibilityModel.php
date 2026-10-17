<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class ProfileVisibilityModel
{
    private function __construct(
        private bool $known,
        public ?HiddenProfileModel $hidden,
    ) {
    }

    public static function unknown(): self
    {
        return new self(false, null);
    }

    public static function publicProfile(): self
    {
        return new self(true, null);
    }

    public static function hiddenProfile(HiddenProfileModel $profile): self
    {
        return new self(true, $profile);
    }

    public function isUnknown(): bool
    {
        return !$this->known;
    }

    public function isPublic(): bool
    {
        return $this->known && null === $this->hidden;
    }
}
