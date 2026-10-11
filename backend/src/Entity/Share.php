<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ShareKind;

final readonly class Share
{
    private function __construct(
        public ?ShareKind $kind,
        public ?string $url,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function link(?string $url): self
    {
        return new self(ShareKind::Link, $url);
    }

    public static function media(?string $url): self
    {
        return new self(ShareKind::Media, $url);
    }

    public static function of(?ShareKind $kind, ?string $url): self
    {
        return $kind === null ? self::none() : new self($kind, $url);
    }

    public function isNone(): bool
    {
        return $this->kind === null;
    }
}
