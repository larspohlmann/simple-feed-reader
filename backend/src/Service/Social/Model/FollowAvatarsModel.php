<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class FollowAvatarsModel
{
    /** @param array<string, string> $byIdentity */
    private function __construct(private array $byIdentity)
    {
    }

    /** @param list<FollowedAccountModel> $follows */
    public static function of(array $follows): self
    {
        $byIdentity = [];
        foreach ($follows as $follow) {
            if (null !== $follow->avatarUrl) {
                $byIdentity[$follow->identity] = $follow->avatarUrl;
            }
        }

        return new self($byIdentity);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function avatarOf(string $identity): ?string
    {
        return $this->byIdentity[$identity] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->byIdentity;
    }

    /** @return array<string, string> */
    public function toCacheEntry(): array
    {
        return $this->byIdentity;
    }

    public static function fromCacheEntryOrNull(mixed $stored): ?self
    {
        if (!\is_array($stored)) {
            return null;
        }
        $byIdentity = [];
        foreach ($stored as $identity => $avatarUrl) {
            if (!\is_string($identity) || !\is_string($avatarUrl)) {
                return null;
            }
            $byIdentity[$identity] = $avatarUrl;
        }

        return new self($byIdentity);
    }
}
