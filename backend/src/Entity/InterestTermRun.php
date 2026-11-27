<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\InvalidRunStatusException;
use App\Enum\RunStatus;
use App\Repository\InterestTermRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One extraction of the account's weighted interest terms from the prose profile generated at one moment. */
#[ORM\Entity(repositoryClass: InterestTermRunRepository::class)]
#[ORM\Table(name: 'interest_term_run')]
#[ORM\Index(name: 'idx_interest_term_run_user', columns: ['user_id'])]
final class InterestTermRun
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

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $profileGeneratedAt;

    #[ORM\Column(length: 16, enumType: RunStatus::class)]
    private RunStatus $status = RunStatus::Running;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    /** Written by RecordedCall straight to the database; this entity only reads it. */
    #[ORM\Column(options: ['default' => 0])]
    private int $streamedChars = 0;

    #[ORM\Embedded(class: RunCallAttempts::class, columnPrefix: false)]
    private RunCallAttempts $callAttempts;

    #[ORM\Embedded(class: ProviderUsage::class, columnPrefix: false)]
    private ProviderUsage $providerUsage;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        User $user,
        \DateTimeImmutable $profileGeneratedAt,
        \DateTimeImmutable $createdAt,
        ?string $providerHost,
        ?string $model,
    ) {
        $this->user = $user;
        $this->profileGeneratedAt = $profileGeneratedAt;
        $this->createdAt = $createdAt;
        $this->callAttempts = new RunCallAttempts();
        $this->providerUsage = new ProviderUsage();
        $this->providerUsage->stamp($providerHost, $model);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProfileGeneratedAt(): \DateTimeImmutable
    {
        return $this->profileGeneratedAt;
    }

    public function isForProfile(\DateTimeImmutable $profileGeneratedAt): bool
    {
        return $this->profileGeneratedAt == $profileGeneratedAt;
    }

    public function getStatus(): RunStatus
    {
        return $this->status;
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

    public function recordInvalidReply(string $reply): void
    {
        $this->guardRunning('record an invalid reply on');
        $this->callAttempts->recordInvalidReply($reply);
    }

    public function hasExhaustedAttempts(): bool
    {
        return $this->callAttempts->attempts() >= self::MAX_ATTEMPTS;
    }

    public function recordTransportFailure(): void
    {
        $this->guardRunning('record a transport failure on');
        $this->callAttempts->recordTransportFailure();
    }

    public function hasExhaustedTransportRetries(): bool
    {
        return $this->callAttempts->transportFailures() >= self::MAX_TRANSPORT_FAILURES;
    }

    public function complete(\DateTimeImmutable $when): void
    {
        $this->guardRunning('complete');

        $this->status = RunStatus::Completed;
        $this->completedAt = $when;
        $this->callAttempts->reset();
    }

    public function fail(string $error, \DateTimeImmutable $when): void
    {
        $this->guardRunning('fail');

        $this->status = RunStatus::Failed;
        $this->completedAt = $when;
        $this->error = $error;
    }

    private function guardRunning(string $transition): void
    {
        if (RunStatus::Running !== $this->status) {
            throw new InvalidRunStatusException($transition, $this->status);
        }
    }
}
