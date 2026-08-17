<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Dto\AccountLine;
use App\Service\Backup\Dto\BackupHeader;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\Dto\EntryStateLine;
use App\Service\Backup\Dto\FeedLine;
use App\Service\Backup\Dto\FooterLine;
use App\Service\Backup\Dto\LineField;
use App\Service\Backup\Dto\SubscriptionLine;
use App\Service\Backup\Dto\TagLine;
use App\Service\Backup\Exception\InvalidBackupException;

/**
 * Reads a backup file front to back, enforcing its grammar: one header first,
 * one account line, then tags, feeds, subscriptions, entries and entry states
 * in that order, closed by a footer whose counts must match what was read.
 * The footer is the truncation guard — without it, a gzip cut exactly at a
 * line boundary would read as a smaller, valid backup and the restore would
 * silently load a partial account.
 */
final readonly class BackupReader
{
    private const array KIND_RANK = [
        BackupSchema::KIND_HEADER => 0,
        BackupSchema::KIND_ACCOUNT => 1,
        BackupSchema::KIND_TAG => 2,
        BackupSchema::KIND_FEED => 3,
        BackupSchema::KIND_SUBSCRIPTION => 4,
        BackupSchema::KIND_ENTRY => 5,
        BackupSchema::KIND_ENTRY_STATE => 6,
        BackupSchema::KIND_FOOTER => 7,
    ];

    /**
     * Kinds that may appear at most once; every other known kind may repeat
     * any number of times (including zero) while its rank holds.
     */
    private const array SINGLETON_KINDS = [
        BackupSchema::KIND_HEADER,
        BackupSchema::KIND_ACCOUNT,
        BackupSchema::KIND_FOOTER,
    ];

    private const array COUNTED_KINDS = [
        BackupSchema::KIND_TAG,
        BackupSchema::KIND_FEED,
        BackupSchema::KIND_SUBSCRIPTION,
        BackupSchema::KIND_ENTRY,
        BackupSchema::KIND_ENTRY_STATE,
    ];

    /**
     * @return \Generator<int, object>
     */
    public function read(string $gzipBytes): \Generator
    {
        $lineNumber = 0;
        $currentRank = -1;
        $counts = array_fill_keys(self::COUNTED_KINDS, 0);
        $accountSeen = false;
        $footerSeen = false;

        foreach (GzipLineReader::lines($gzipBytes) as $line) {
            ++$lineNumber;
            if ('' === $line) {
                continue;
            }

            if ($footerSeen) {
                throw new InvalidBackupException(sprintf('Line %d appears after the footer.', $lineNumber));
            }

            $decoded = $this->decodeLine($line, $lineNumber);
            $kind = LineField::string($decoded, 'kind');

            $currentRank = $this->assertOrdered($kind, -1 === $currentRank, $currentRank, $lineNumber);

            if (BackupSchema::KIND_FOOTER === $kind) {
                $this->assertAccountSeen($accountSeen);
                $this->verifyFooter(FooterLine::fromLine($decoded), $counts);
                $footerSeen = true;
                continue;
            }

            if (BackupSchema::KIND_ACCOUNT === $kind) {
                $accountSeen = true;
            }

            if (\in_array($kind, self::COUNTED_KINDS, true)) {
                ++$counts[$kind];
            }

            yield $this->toDto($kind, $decoded);
        }

        if (!$footerSeen) {
            throw new InvalidBackupException('The file is truncated — the closing footer line is missing.');
        }
    }

    private function assertAccountSeen(bool $accountSeen): void
    {
        if (!$accountSeen) {
            throw new InvalidBackupException('The backup is missing its account line.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeLine(string $line, int $lineNumber): array
    {
        try {
            $decoded = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidBackupException(sprintf('Malformed JSON at line %d.', $lineNumber));
        }

        if (!\is_array($decoded)) {
            throw new InvalidBackupException(sprintf('Line %d is not a JSON object.', $lineNumber));
        }

        $fields = [];
        foreach ($decoded as $key => $value) {
            if (!\is_string($key)) {
                throw new InvalidBackupException(sprintf('Line %d is not a JSON object.', $lineNumber));
            }

            $fields[$key] = $value;
        }

        return $fields;
    }

    /**
     * Enforces the file grammar: the first line must be a header, kind ranks
     * never move backwards, and a singleton kind (header/account/footer)
     * cannot repeat.
     */
    private function assertOrdered(string $kind, bool $isFirstLine, int $currentRank, int $lineNumber): int
    {
        $newRank = self::KIND_RANK[$kind] ?? null;
        if (null === $newRank) {
            throw new InvalidBackupException(sprintf('Line %d has an unknown kind "%s".', $lineNumber, $kind));
        }

        if ($isFirstLine) {
            if (BackupSchema::KIND_HEADER !== $kind) {
                throw new InvalidBackupException('The first line must be a header.');
            }

            return $newRank;
        }

        if ($newRank < $currentRank) {
            throw new InvalidBackupException(sprintf('Line %d is out of order.', $lineNumber));
        }

        if ($newRank === $currentRank && \in_array($kind, self::SINGLETON_KINDS, true)) {
            throw new InvalidBackupException(sprintf('Line %d repeats the singleton kind "%s".', $lineNumber, $kind));
        }

        return $newRank;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function toDto(string $kind, array $decoded): object
    {
        return match ($kind) {
            BackupSchema::KIND_HEADER => $this->assertKnownSchemaVersion(BackupHeader::fromLine($decoded)),
            BackupSchema::KIND_ACCOUNT => AccountLine::fromLine($decoded),
            BackupSchema::KIND_TAG => TagLine::fromLine($decoded),
            BackupSchema::KIND_FEED => FeedLine::fromLine($decoded),
            BackupSchema::KIND_SUBSCRIPTION => SubscriptionLine::fromLine($decoded),
            BackupSchema::KIND_ENTRY => EntryLine::fromLine($decoded),
            BackupSchema::KIND_ENTRY_STATE => EntryStateLine::fromLine($decoded),
            // Unreachable: assertOrdered has already refused every kind absent
            // from KIND_RANK, and KIND_RANK and this match list the same set.
            // It stays only so the match is exhaustive over `string`.
            default => throw new \LogicException(sprintf('assertOrdered accepted unknown kind "%s".', $kind)),
        };
    }

    private function assertKnownSchemaVersion(BackupHeader $header): BackupHeader
    {
        if (BackupSchema::VERSION !== $header->schemaVersion) {
            throw new InvalidBackupException(sprintf(
                'Unsupported schema version %d; this instance reads version %d.',
                $header->schemaVersion,
                BackupSchema::VERSION,
            ));
        }

        return $header;
    }

    /**
     * @param array<string, int> $actualCounts
     */
    private function verifyFooter(FooterLine $footer, array $actualCounts): void
    {
        foreach (self::COUNTED_KINDS as $kind) {
            $expected = $footer->counts[$kind] ?? null;
            if ($expected !== $actualCounts[$kind]) {
                throw new InvalidBackupException(sprintf(
                    'Footer count for "%s" is %s but %d lines were read.',
                    $kind,
                    null === $expected ? 'missing' : (string) $expected,
                    $actualCounts[$kind],
                ));
            }
        }
    }
}
