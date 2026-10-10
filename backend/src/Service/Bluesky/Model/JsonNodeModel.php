<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Model;

/** One object of an AppView answer, read field by field: a field of the wrong type reads as absent. */
final readonly class JsonNodeModel
{
    /** @param array<mixed> $fields */
    private function __construct(private array $fields)
    {
    }

    public static function of(mixed $value): self
    {
        return new self(\is_array($value) ? $value : []);
    }

    public function type(): ?string
    {
        return $this->string('$type');
    }

    public function string(string $key): ?string
    {
        $value = $this->fields[$key] ?? null;
        if (!\is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public function int(string $key): ?int
    {
        $value = $this->fields[$key] ?? null;

        return \is_int($value) ? $value : null;
    }

    public function node(string $key): self
    {
        return self::of($this->fields[$key] ?? null);
    }

    /** @return list<self> */
    public function nodes(string $key): array
    {
        $value = $this->fields[$key] ?? null;

        return \is_array($value) && array_is_list($value) ? array_map(self::of(...), $value) : [];
    }
}
