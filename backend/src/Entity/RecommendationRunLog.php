<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RecommendationRunLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One provider-call attempt of a recommendation run, recorded for the debug
 * view (#309): the request body the moment it was sent, the response text as
 * it streams in (checkpointed every ~2 s by RecordedCall via direct DBAL
 * updates), and the parser's verdict once the call ended. Rows exist only
 * while the debug switch is on and only for the latest run — the next run
 * start wipes them.
 *
 * LONGTEXT length: a #308 batch request over a large context window is
 * hundreds of KB, past MySQL TEXT's 64 KB.
 */
#[ORM\Entity(repositoryClass: RecommendationRunLogRepository::class)]
#[ORM\Table(name: 'recommendation_run_log')]
#[ORM\Index(name: 'idx_recommendation_run_log_run', columns: ['run_id'])]
class RecommendationRunLog
{
    public const string PHASE_BATCH = 'batch';
    public const string PHASE_DEDUP = 'dedup';

    public const string VERDICT_USABLE = 'usable';
    public const string VERDICT_UNUSABLE = 'unusable';
    public const string VERDICT_TRANSPORT_FAILED = 'transport-failed';

    private const int LONGTEXT_LENGTH = 4_294_967_295;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RecommendationRun::class)]
    #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'CASCADE')]
    private RecommendationRun $run;

    #[ORM\Column(length: 16)]
    private string $phase;

    #[ORM\Column(nullable: true)]
    private ?int $batchNumber;

    #[ORM\Column]
    private int $attempt;

    #[ORM\Column(type: Types::TEXT, length: self::LONGTEXT_LENGTH)]
    private string $requestBody;

    #[ORM\Column(type: Types::TEXT, length: self::LONGTEXT_LENGTH)]
    private string $responseText = '';

    /** Null while the call is still streaming. */
    #[ORM\Column(length: 24, nullable: true)]
    private ?string $verdict = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        RecommendationRun $run,
        string $phase,
        ?int $batchNumber,
        int $attempt,
        string $requestBody,
        \DateTimeImmutable $updatedAt,
    ) {
        $this->run = $run;
        $this->phase = $phase;
        $this->batchNumber = $batchNumber;
        $this->attempt = $attempt;
        $this->requestBody = $requestBody;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRun(): RecommendationRun
    {
        return $this->run;
    }

    public function getPhase(): string
    {
        return $this->phase;
    }

    public function getBatchNumber(): ?int
    {
        return $this->batchNumber;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function getRequestBody(): string
    {
        return $this->requestBody;
    }

    public function getResponseText(): string
    {
        return $this->responseText;
    }

    public function getVerdict(): ?string
    {
        return $this->verdict;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * The call ended: the final decoded text replaces whatever partial state
     * the checkpoints wrote, and the verdict says how the reply was judged.
     */
    public function finish(string $responseText, string $verdict, \DateTimeImmutable $when): void
    {
        $this->responseText = $responseText;
        $this->verdict = $verdict;
        $this->updatedAt = $when;
    }
}
