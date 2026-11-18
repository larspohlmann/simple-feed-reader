<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StoryAiTask;
use App\Repository\StoryAiRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One story AI task's calls and what they cost, banked by RecordedCall; every story task shares this table. */
#[ORM\Entity(repositoryClass: StoryAiRunRepository::class)]
#[ORM\Table(name: 'story_ai_run')]
#[ORM\Index(name: 'idx_story_ai_run_user', columns: ['user_id'])]
final class StoryAiRun
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: StoryAiTask::class)]
    private StoryAiTask $task;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Written by RecordedCall straight to the database; this entity only reads it. */
    #[ORM\Column(options: ['default' => 0])]
    private int $streamedChars = 0;

    #[ORM\Embedded(class: ProviderUsage::class, columnPrefix: false)]
    private ProviderUsage $providerUsage;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(User $user, StoryAiTask $task, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->task = $task;
        $this->createdAt = $createdAt;
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

    public function getTask(): StoryAiTask
    {
        return $this->task;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStreamedChars(): int
    {
        return $this->streamedChars;
    }
}
