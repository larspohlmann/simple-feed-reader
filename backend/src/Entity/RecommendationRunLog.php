<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Repository\RecommendationRunLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One provider-call attempt: the request as sent, the response as it streams (RecordedCall writes it straight to the
 * database) and the verdict. Kept for the newest RunLogRetention::RUNS runs, debug on or off: the ETA averages these
 * rows. LONGTEXT, because one batch request over a large context window passes MySQL TEXT's 64 KB.
 */
#[ORM\Entity(repositoryClass: RecommendationRunLogRepository::class)]
#[ORM\Table(name: 'recommendation_run_log')]
#[ORM\Index(name: 'idx_recommendation_run_log_run', columns: ['run_id'])]
final class RecommendationRunLog
{
    use PersistedId;

    private const int LONGTEXT_LENGTH = 4_294_967_295;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RecommendationRun::class)]
    #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'CASCADE')]
    private RecommendationRun $run;

    #[ORM\Column(length: 16, enumType: CallPhase::class)]
    private CallPhase $phase;

    #[ORM\Column(nullable: true)]
    private ?int $batchNumber;

    #[ORM\Column]
    private int $attempt;

    #[ORM\Column(type: Types::TEXT, length: self::LONGTEXT_LENGTH)]
    private string $requestBody;

    #[ORM\Column(type: Types::TEXT, length: self::LONGTEXT_LENGTH)]
    private string $responseText = '';

    /** Null while the call is still streaming. */
    #[ORM\Column(length: 24, nullable: true, enumType: CallVerdict::class)]
    private ?CallVerdict $verdict = null;

    /** Every byte the provider sent: a reasoning model can send megabytes while $responseText stays empty. */
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

    /** Why the provider stopped: `length` when max_tokens truncated the answer, `stop` at a natural end. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $finishReason = null;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        RecommendationRun $run,
        CallPhase $phase,
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

    public function getPhase(): CallPhase
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

    public function getVerdict(): ?CallVerdict
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
}
