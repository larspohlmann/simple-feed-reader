<?php

declare(strict_types=1);

namespace App\Service\Reading\Model;

use App\Enum\SocialNetwork;

final readonly class ReadScopeModel
{
    private function __construct(
        public ReadScopeKind $kind,
        private ?int $id,
        private ?SocialNetwork $network = null,
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

    public static function network(SocialNetwork $network): self
    {
        return new self(ReadScopeKind::Network, null, $network);
    }

    public function targetId(): int
    {
        return $this->id
            ?? throw new \LogicException(sprintf('A "%s" scope names no subscription or tag.', $this->kind->value));
    }

    public function targetNetwork(): SocialNetwork
    {
        return $this->network
            ?? throw new \LogicException(sprintf('A "%s" scope names no network.', $this->kind->value));
    }
}
