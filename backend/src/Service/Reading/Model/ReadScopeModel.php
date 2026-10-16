<?php

declare(strict_types=1);

namespace App\Service\Reading\Model;

use App\Repository\NetworkScope;

final readonly class ReadScopeModel
{
    private function __construct(
        public ReadScopeKind $kind,
        private ?int $id,
        private ?NetworkScope $networkScope = null,
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

    public static function network(NetworkScope $networkScope): self
    {
        return new self(ReadScopeKind::Network, null, $networkScope);
    }

    public function targetId(): int
    {
        return $this->id
            ?? throw new \LogicException(sprintf('A "%s" scope names no subscription or tag.', $this->kind->value));
    }

    public function targetNetworkScope(): NetworkScope
    {
        return $this->networkScope
            ?? throw new \LogicException(sprintf('A "%s" scope names no network.', $this->kind->value));
    }
}
