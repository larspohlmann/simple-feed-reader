<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Exception\InvalidBackupException;

/**
 * The file grammar: a header first, kind ranks never moving backwards, header/account/footer at most once.
 */
final readonly class BackupLineOrder
{
    private const array KIND_RANK = [
        BackupSchema::KIND_HEADER => 0,
        BackupSchema::KIND_ACCOUNT => 1,
        BackupSchema::KIND_TAG => 2,
        BackupSchema::KIND_SAVED_SEARCH => 3,
        BackupSchema::KIND_FEED => 4,
        BackupSchema::KIND_SUBSCRIPTION => 5,
        BackupSchema::KIND_ENTRY => 6,
        BackupSchema::KIND_ENTRY_STATE => 7,
        BackupSchema::KIND_FOOTER => 8,
    ];

    private const array SINGLETON_KINDS = [
        BackupSchema::KIND_HEADER,
        BackupSchema::KIND_ACCOUNT,
        BackupSchema::KIND_FOOTER,
    ];

    private function __construct(private ?int $lastRank)
    {
    }

    public static function beforeTheFirstLine(): self
    {
        return new self(null);
    }

    /**
     * @throws InvalidBackupException
     */
    public function admit(string $kind, int $lineNumber): self
    {
        $rank = self::KIND_RANK[$kind]
            ?? throw new InvalidBackupException(sprintf('Line %d has an unknown kind "%s".', $lineNumber, $kind));

        if (null === $this->lastRank) {
            return self::opening($kind, $rank);
        }

        if ($rank < $this->lastRank) {
            throw new InvalidBackupException(sprintf('Line %d is out of order.', $lineNumber));
        }

        if ($rank === $this->lastRank && \in_array($kind, self::SINGLETON_KINDS, true)) {
            throw new InvalidBackupException(sprintf('Line %d repeats the singleton kind "%s".', $lineNumber, $kind));
        }

        return new self($rank);
    }

    /**
     * @throws InvalidBackupException
     */
    private static function opening(string $kind, int $rank): self
    {
        if (BackupSchema::KIND_HEADER !== $kind) {
            throw new InvalidBackupException('The first line must be a header.');
        }

        return new self($rank);
    }
}
