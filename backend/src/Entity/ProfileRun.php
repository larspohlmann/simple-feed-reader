<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\InvalidRunStatusException;
use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Enum\RunStatus;
use App\Repository\ProfileRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One generation of the account's interest profile, ticked under the lock its recommendation runs take. */
#[ORM\Entity(repositoryClass: ProfileRunRepository::class)]
#[ORM\Table(name: 'profile_run')]
#[ORM\Index(name: 'idx_profile_run_user_status', columns: ['user_id', 'status'])]
final class ProfileRun
{
    use PersistedId;

    public const int MAX_ATTEMPTS = 3;

    public const int MAX_TRANSPORT_FAILURES = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: RunStatus::class)]
    private RunStatus $status = RunStatus::Pending;

    #[ORM\Column(name: 'run_trigger', length: 16, enumType: ProfileRunTrigger::class)]
    private ProfileRunTrigger $trigger;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $fingerprint = null;

    #[ORM\Column(length: 16, nullable: true, enumType: ProfileRunOutcome::class)]
    private ?ProfileRunOutcome $outcome = null;

    /** Written by RecordedCall straight to the database; this entity only reads it. */
    #[ORM\Column(options: ['default' => 0])]
    private int $streamedChars = 0;

    #[ORM\Embedded(class: RunCallAttempts::class, columnPrefix: false)]
    private RunCallAttempts $callAttempts;

    #[ORM\Embedded(class: ProviderUsage::class, columnPrefix: false)]
    private ProviderUsage $providerUsage;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(User $user, ProfileRunTrigger $trigger, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->trigger = $trigger;
        $this->createdAt = $createdAt;
        $this->callAttempts = new RunCallAttempts();
        $this->providerUsage = new ProviderUsage();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStatus(): RunStatus
    {
        return $this->status;
    }

    public function getTrigger(): ProfileRunTrigger
    {
        return $this->trigger;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function getOutcome(): ?ProfileRunOutcome
    {
        return $this->outcome;
    }

    public function getStreamedChars(): int
    {
        return $this->streamedChars;
    }

    public function getAttempts(): int
    {
        return $this->callAttempts->attempts();
    }

    public function getTransportFailures(): int
    {
        return $this->callAttempts->transportFailures();
    }

    public function getLastInvalidReply(): ?string
    {
        return $this->callAttempts->lastInvalidReply();
    }

    public function getProviderHost(): ?string
    {
        return $this->providerUsage->getProviderHost();
    }

    public function getModel(): ?string
    {
        return $this->providerUsage->getModel();
    }

    public function getPromptTokens(): int
    {
        return $this->providerUsage->getPromptTokens();
    }

    public function getCompletionTokens(): int
    {
        return $this->providerUsage->getCompletionTokens();
    }

    public function getCostNanoCredits(): ?int
    {
        return $this->providerUsage->getCostNanoCredits();
    }

    public function start(string $fingerprint, ?string $providerHost, string $model): void
    {
        $this->guardStatus(RunStatus::Pending, 'start');

        $this->fingerprint = $fingerprint;
        $this->providerUsage->stamp($providerHost, $model);
        $this->status = RunStatus::Running;
    }

    public function recordInvalidReply(string $reply): void
    {
        $this->guardStatus(RunStatus::Running, 'record an invalid reply on');
        $this->callAttempts->recordInvalidReply($reply);
    }

    public function hasExhaustedAttempts(): bool
    {
        return $this->callAttempts->attempts() >= self::MAX_ATTEMPTS;
    }

    public function recordTransportFailure(): void
    {
        $this->guardStatus(RunStatus::Running, 'record a transport failure on');
        $this->callAttempts->recordTransportFailure();
    }

    public function hasExhaustedTransportRetries(): bool
    {
        return $this->callAttempts->transportFailures() >= self::MAX_TRANSPORT_FAILURES;
    }

    public function complete(ProfileRunOutcome $outcome, \DateTimeImmutable $when): void
    {
        $this->guardStatus(RunStatus::Running, 'complete');

        $this->status = RunStatus::Completed;
        $this->completedAt = $when;
        $this->outcome = $outcome;
        $this->callAttempts->reset();
    }

    /** Also legal from PENDING: a run that finds no usable connection never starts. */
    public function fail(string $error, \DateTimeImmutable $when): void
    {
        if (!$this->status->isActive()) {
            throw new InvalidRunStatusException('fail', $this->status);
        }

        $this->status = RunStatus::Failed;
        $this->completedAt = $when;
        $this->error = $error;
    }

    private function guardStatus(RunStatus $requiredStatus, string $transition): void
    {
        if ($requiredStatus !== $this->status) {
            throw new InvalidRunStatusException($transition, $this->status);
        }
    }
}
