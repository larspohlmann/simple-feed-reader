<?php

declare(strict_types=1);

namespace App\Service\Reading;

final readonly class ReadScope
{
    private function __construct(
        public ReadScopeKind $kind,
        public ?int $id,
    ) {
    }

    public static function all(): self
    {
        return new self(ReadScopeKind::All, null);
    }

    public static function feed(int $subscriptionId): self
    {
        return new self(ReadScopeKind::Feed, $subscriptionId);
    }

    public static function tag(int $tagId): self
    {
        return new self(ReadScopeKind::Tag, $tagId);
    }

    public function targetId(): int
    {
        return $this->id ?? throw new \LogicException('An "all" scope names no subscription or tag.');
    }
}
