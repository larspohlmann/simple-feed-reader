<?php

declare(strict_types=1);

namespace App\Service\SelfUpdate\Pass;

use App\Service\SelfUpdate\Exception\MalformedUpdateStateException;
use App\Service\SelfUpdate\Model\SelfUpdatePhase;

final readonly class StateFileFields
{
    /** @param array<array-key, mixed> $fields */
    public function __construct(
        private string $fileName,
        private array $fields,
    ) {
    }

    public function requiredString(string $name): string
    {
        $value = $this->fields[$name] ?? null;
        if (!is_string($value) || '' === $value) {
            throw new MalformedUpdateStateException(sprintf('%s carries no "%s" string.', $this->fileName, $name));
        }

        return $value;
    }

    public function phase(string $name): SelfUpdatePhase
    {
        $phase = SelfUpdatePhase::tryFrom($this->requiredString($name));
        if (null === $phase) {
            throw new MalformedUpdateStateException(sprintf('%s carries an unknown "%s".', $this->fileName, $name));
        }

        return $phase;
    }

    public function dateOrNull(string $name): ?\DateTimeImmutable
    {
        $value = $this->fields[$name] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($this->requiredString($name)))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\DateMalformedStringException $exception) {
            throw new MalformedUpdateStateException(
                sprintf('%s carries an unreadable "%s".', $this->fileName, $name),
                previous: $exception,
            );
        }
    }

    public function integerOrNull(string $name): ?int
    {
        $value = $this->fields[$name] ?? null;
        if (null !== $value && !is_int($value)) {
            throw new MalformedUpdateStateException(sprintf('%s carries a non-integer "%s".', $this->fileName, $name));
        }

        return $value;
    }
}
