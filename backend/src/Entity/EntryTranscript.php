<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TranscriptFormat;
use App\Enum\TranscriptStatus;
use App\Repository\EntryTranscriptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An episode's declared transcript: queued at ingest, filled by TranscriptSweep or on open. Owned from this side,
 * never mapped on Entry: an inverse one-to-one is always loaded eagerly, which would pull ~100 KB into every list.
 */
#[ORM\Entity(repositoryClass: EntryTranscriptRepository::class)]
#[ORM\Table(name: 'entry_transcript')]
#[ORM\Index(name: 'idx_entry_transcript_claimable', columns: ['status', 'next_attempt_at'])]
final class EntryTranscript
{
    public const int URL_MAX_LENGTH = 2048;
    public const int LANGUAGE_MAX_LENGTH = 35;
    public const int MAX_ATTEMPTS = 5;
    private const int BACKOFF_BASE_MINUTES = 15;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column(type: Types::TEXT)]
    private string $url;

    #[ORM\Column(length: 8, enumType: TranscriptFormat::class)]
    private TranscriptFormat $format;

    #[ORM\Column(length: self::LANGUAGE_MAX_LENGTH, nullable: true)]
    private ?string $language;

    #[ORM\Column(length: 8, enumType: TranscriptStatus::class)]
    private TranscriptStatus $status = TranscriptStatus::Pending;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(name: 'next_attempt_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextAttemptAt = null;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $paragraphs = null;

    #[ORM\Column(name: 'search_text', type: Types::TEXT, nullable: true)]
    private ?string $searchText = null;

    public function __construct(Entry $entry, string $url, TranscriptFormat $format, ?string $language)
    {
        $this->entry = $entry;
        $this->url = $url;
        $this->format = $format;
        $this->language = $language;
    }

    public function requireId(): int
    {
        return $this->id ?? throw new \LogicException('An entry transcript has no id before it is flushed.');
    }

    /** @param list<TranscriptParagraph> $paragraphs */
    public function fill(array $paragraphs, string $searchText): void
    {
        $this->paragraphs = array_map(
            static fn (TranscriptParagraph $paragraph): array => $paragraph->jsonSerialize(),
            $paragraphs,
        );
        $this->searchText = $searchText;
        $this->status = TranscriptStatus::Filled;
        $this->nextAttemptAt = null;
    }

    public function fail(): void
    {
        $this->status = TranscriptStatus::Failed;
        $this->nextAttemptAt = null;
    }

    public function retryAfter(\DateTimeImmutable $now): void
    {
        $this->attempts++;
        if ($this->attempts >= self::MAX_ATTEMPTS) {
            $this->fail();

            return;
        }

        $minutes = self::BACKOFF_BASE_MINUTES * 2 ** $this->attempts;
        $this->nextAttemptAt = $now->modify(\sprintf('+%d minutes', $minutes));
    }

    public function isPending(): bool
    {
        return $this->status === TranscriptStatus::Pending;
    }

    public function isFilled(): bool
    {
        return $this->status === TranscriptStatus::Filled;
    }

    public function isFailed(): bool
    {
        return $this->status === TranscriptStatus::Failed;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getFormat(): TranscriptFormat
    {
        return $this->format;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function getNextAttemptAt(): ?\DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    /** @return list<TranscriptParagraph> */
    public function getParagraphs(): array
    {
        return StoredList::read(
            $this->paragraphs,
            TranscriptParagraph::isComplete(...),
            TranscriptParagraph::fromStored(...),
        );
    }

    public function getSearchText(): ?string
    {
        return $this->searchText;
    }
}
