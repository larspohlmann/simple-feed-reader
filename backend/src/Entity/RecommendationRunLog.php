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

    /**
     * Every byte the provider sent, not just the ones that decoded into the
     * answer. A reasoning model spends megabytes here while $responseText
     * stays empty, and without this the panel cannot tell that call apart
     * from a provider that never spoke (#320).
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $wireBytes = 0;

    /** When the request went out. */
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** When the call settled (any verdict). Null while streaming. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** The transport exception's message, for transport-failed calls only. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorDetail = null;

    /**
     * The provider's own account of why generation stopped — `length` when
     * `max_tokens` truncated the answer, `stop` on a natural end. Null until
     * the provider stamps it. It is what tells a truncated answer apart from a
     * model that merely rambled, the diagnosis the log could not make before
     * #327.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $finishReason = null;

    public function __construct(
        RecommendationRun $run,
        string $phase,
        ?int $batchNumber,
        int $attempt,
        string $requestBody,
        \DateTimeImmutable $createdAt,
    ) {
        $this->run = $run;
        $this->phase = $phase;
        $this->batchNumber = $batchNumber;
        $this->attempt = $attempt;
        $this->requestBody = $requestBody;
        $this->createdAt = $createdAt;
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

    public function getWireBytes(): int
    {
        return $this->wireBytes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getErrorDetail(): ?string
    {
        return $this->errorDetail;
    }

    public function getFinishReason(): ?string
    {
        return $this->finishReason;
    }

    /**
     * The call ended: the final decoded text replaces whatever partial state
     * the checkpoints wrote, the verdict says how the reply was judged, the
     * byte count says what it cost on the wire to get there, and the finish
     * reason says why the provider stopped.
     */
    public function finish(
        string $responseText,
        string $verdict,
        int $wireBytes,
        ?string $finishReason,
        \DateTimeImmutable $finishedAt,
    ): void {
        $this->responseText = $responseText;
        $this->verdict = $verdict;
        $this->wireBytes = $wireBytes;
        $this->finishReason = $finishReason;
        $this->finishedAt = $finishedAt;
    }
}
