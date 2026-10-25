<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\IncompleteStoredMediaException;

final readonly class EntryChapter implements \JsonSerializable
{
    public function __construct(public int $startSeconds, public string $title)
    {
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @phpstan-assert-if-true array{startSeconds: int, title: string, ...<mixed>} $stored
     */
    public static function isComplete(array $stored): bool
    {
        return \is_int($stored['startSeconds'] ?? null) && \is_string($stored['title'] ?? null);
    }

    /** @param array<string, mixed> $stored */
    public static function fromStored(array $stored): self
    {
        if (!self::isComplete($stored)) {
            throw new IncompleteStoredMediaException('A stored chapter needs a start and a title.');
        }

        return new self($stored['startSeconds'], $stored['title']);
    }

    /** @return array{startSeconds: int, title: string} */
    public function jsonSerialize(): array
    {
        return ['startSeconds' => $this->startSeconds, 'title' => $this->title];
    }
}
