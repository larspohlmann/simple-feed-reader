<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\IncompleteStoredMediaException;

final readonly class TranscriptParagraph implements \JsonSerializable
{
    public function __construct(public ?int $startSeconds, public ?string $speaker, public string $text)
    {
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @phpstan-assert-if-true array{startSeconds: int|null, speaker: string|null, text: string, ...<mixed>} $stored
     */
    public static function isComplete(array $stored): bool
    {
        $start = $stored['startSeconds'] ?? null;
        $speaker = $stored['speaker'] ?? null;

        return ($start === null || \is_int($start))
            && ($speaker === null || \is_string($speaker))
            && \is_string($stored['text'] ?? null);
    }

    /** @param array<string, mixed> $stored */
    public static function fromStored(array $stored): self
    {
        if (!self::isComplete($stored)) {
            throw new IncompleteStoredMediaException('A stored transcript paragraph needs its text.');
        }

        return new self($stored['startSeconds'] ?? null, $stored['speaker'] ?? null, $stored['text']);
    }

    /** @return array{startSeconds: int|null, speaker: string|null, text: string} */
    public function jsonSerialize(): array
    {
        return ['startSeconds' => $this->startSeconds, 'speaker' => $this->speaker, 'text' => $this->text];
    }
}
