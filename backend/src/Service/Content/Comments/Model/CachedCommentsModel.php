<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

final readonly class CachedCommentsModel
{
    public function __construct(public string $body, public \DateTimeImmutable $fetchedAt)
    {
    }

    /** @return array{body: string, fetchedAt: int} */
    public function toCacheEntry(): array
    {
        return ['body' => $this->body, 'fetchedAt' => $this->fetchedAt->getTimestamp()];
    }

    public static function fromCacheEntryOrNull(mixed $stored): ?self
    {
        if (!\is_array($stored)) {
            return null;
        }
        $body = $stored['body'] ?? null;
        $fetchedAt = $stored['fetchedAt'] ?? null;
        if (!\is_string($body) || !\is_int($fetchedAt)) {
            return null;
        }

        return new self($body, new \DateTimeImmutable('@' . $fetchedAt));
    }
}
