<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Service\Backup\Support\LineField;

/** One article the account shared and when, matched to a restored entry by (feed, guidHash) like a state line. */
final readonly class EntryShareLine
{
    public function __construct(
        public string $feedUrl,
        public string $guidHash,
        public \DateTimeImmutable $sharedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): self
    {
        return new self(
            feedUrl: LineField::string($line, 'feedUrl'),
            guidHash: LineField::string($line, 'guidHash'),
            sharedAt: LineField::date($line, 'sharedAt'),
        );
    }
}
