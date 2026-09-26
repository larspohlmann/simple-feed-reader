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
use App\Service\Backup\Dto\SavedSearchLine;
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
    public const int MAX_ENTRIES_PER_PART = 5000;
    public const int MAX_INFLATED_BYTES = 67_108_864;

    private const array COUNTED_KINDS = [
        BackupSchema::KIND_TAG,
        BackupSchema::KIND_SAVED_SEARCH,
        BackupSchema::KIND_FEED,
        BackupSchema::KIND_SUBSCRIPTION,
        BackupSchema::KIND_ENTRY,
        BackupSchema::KIND_ENTRY_STATE,
    ];

    public function __construct(
        private int $maxInflatedBytes = self::MAX_INFLATED_BYTES,
    ) {
    }

    /**
     * @return \Generator<int, object>
     */
    public function read(string $gzipBytes): \Generator
    {
        $lineNumber = 0;
        $order = BackupLineOrder::beforeTheFirstLine();
        $counts = array_fill_keys(self::COUNTED_KINDS, 0);
        $accountSeen = false;
        $footerSeen = false;
        $header = null;
        $guard = null;
        $inflatedBytes = 0;

        // One line may not out-grow the whole part's budget, so the part ceiling bounds the line too.
        foreach (GzipLineReader::lines($gzipBytes, $this->maxInflatedBytes) as $line) {
            ++$lineNumber;
            $inflatedBytes += \strlen($line) + 1;
            $this->assertUnderByteCeiling($inflatedBytes);

            if ('' === $line) {
                continue;
            }

            if ($footerSeen) {
                throw new InvalidBackupException(sprintf('Line %d appears after the footer.', $lineNumber));
            }

            $decoded = $this->decodeLine($line, $lineNumber);
            $kind = LineField::string($decoded, 'kind');

            $order = $order->admit($kind, $lineNumber);

            if (BackupSchema::KIND_HEADER === $kind) {
                $this->assertKnownSchemaVersion($decoded);
                $header = BackupHeader::fromLine($decoded)->requireCoherent();
                $guard = new BackupPartGuard($header);
                yield $header;
                continue;
            }

            $guard = $this->requireGuard($guard);

            if (BackupSchema::KIND_FOOTER === $kind) {
                $this->assertAccountSeen($header, $accountSeen);
                $this->verifyFooter(FooterLine::fromLine($decoded), $counts);
                $footerSeen = true;
                continue;
            }

            $guard->seeKind($kind, $lineNumber);

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

    /**
     * Counted for every line before the blank-line skip, so a gzip of nothing
     * but newlines cannot inflate past the ceiling uncounted.
     */
    private function assertUnderByteCeiling(int $inflatedBytes): void
    {
        if ($inflatedBytes > $this->maxInflatedBytes) {
            throw new InvalidBackupException(
                sprintf('The backup inflates past %d bytes.', $this->maxInflatedBytes),
            );
        }
    }

    /**
     * The grammar guarantees a header precedes every other line, so a null
     * guard here means BackupLineOrder failed to do its job.
     */
    private function requireGuard(?BackupPartGuard $guard): BackupPartGuard
    {
        if (null === $guard) {
            throw new \LogicException('No header was read before this line.');
        }

        return $guard;
    }

    private function assertAccountSeen(?BackupHeader $header, bool $accountSeen): void
    {
        if (null === $header || !$header->isFoundation()) {
            return;
        }

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
     * @param array<string, mixed> $decoded
     */
    private function toDto(string $kind, array $decoded): object
    {
        return match ($kind) {
            BackupSchema::KIND_ACCOUNT => AccountLine::fromLine($decoded),
            BackupSchema::KIND_TAG => TagLine::fromLine($decoded),
            BackupSchema::KIND_SAVED_SEARCH => SavedSearchLine::fromLine($decoded),
            BackupSchema::KIND_FEED => FeedLine::fromLine($decoded),
            BackupSchema::KIND_SUBSCRIPTION => SubscriptionLine::fromLine($decoded),
            BackupSchema::KIND_ENTRY => EntryLine::fromLine($decoded),
            BackupSchema::KIND_ENTRY_STATE => EntryStateLine::fromLine($decoded),
            // Unreachable: read() handles header/footer, and BackupLineOrder
            // refuses any other kind. Stays only for match exhaustiveness.
            default => throw new \LogicException(sprintf('BackupLineOrder admitted unknown kind "%s".', $kind)),
        };
    }

    /**
     * Checked from the raw line before BackupHeader::fromLine parses the
     * version-3-only fields, so a real version-2 file reports its version
     * rather than a missing "backupId".
     *
     * @param array<string, mixed> $decoded
     */
    private function assertKnownSchemaVersion(array $decoded): void
    {
        $schemaVersion = LineField::int($decoded, 'schemaVersion');
        if (BackupSchema::VERSION !== $schemaVersion) {
            throw new InvalidBackupException(sprintf(
                'Unsupported schema version %d; this instance reads version %d.',
                $schemaVersion,
                BackupSchema::VERSION,
            ));
        }
    }

    /**
     * A count key missing from the footer defaults to zero rather than
     * failing outright: a file written before its kind existed (savedSearch
     * joined after tag, feed, subscription, entry and entryState, still
     * under the same schema version) never declares it, and genuinely has
     * zero such lines. A missing key paired with a nonzero actual count is
     * still refused — that combination cannot happen from age alone.
     *
     * @param array<string, int> $actualCounts
     */
    private function verifyFooter(FooterLine $footer, array $actualCounts): void
    {
        foreach (self::COUNTED_KINDS as $kind) {
            $expected = $footer->counts[$kind] ?? 0;
            if ($expected !== $actualCounts[$kind]) {
                throw new InvalidBackupException(sprintf(
                    'Footer count for "%s" is %s but %d lines were read.',
                    $kind,
                    \array_key_exists($kind, $footer->counts) ? (string) $expected : 'missing',
                    $actualCounts[$kind],
                ));
            }
        }
    }
}
