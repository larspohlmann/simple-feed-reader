<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PrivateFeedSyncSkip;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * When an account's lists and feeds were last checked against the network, when a check last completed, and why the
 * last one was skipped (#1666). A skip keeps syncedAt: the baseline waits for the first complete answer.
 */
#[ORM\Embeddable]
final readonly class SyncStamp
{
    public function __construct(
        #[ORM\Column(name: 'last_checked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $checkedAt = null,
        #[ORM\Column(name: 'last_synced_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $syncedAt = null,
        #[ORM\Column(name: 'last_sync_skip', length: 24, nullable: true, enumType: PrivateFeedSyncSkip::class)]
        private ?PrivateFeedSyncSkip $skip = null,
    ) {
    }

    public static function completed(\DateTimeImmutable $at): self
    {
        return new self($at, $at);
    }

    public function skipped(\DateTimeImmutable $at, PrivateFeedSyncSkip $skip): self
    {
        return new self($at, $this->syncedAt, $skip);
    }

    public function checkedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function syncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function skip(): ?PrivateFeedSyncSkip
    {
        return $this->skip;
    }
}
