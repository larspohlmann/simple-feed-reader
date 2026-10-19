<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PrivateFeedSyncSkip;
use App\Enum\SocialFeedKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * When an account's lists and feeds were last checked against the network, when a check last completed, which kinds
 * that check covered, and why the last one was skipped (#1666). A skip keeps both: a kind's baseline waits for the
 * first complete answer that covers it.
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
        /** @var list<string>|null */
        #[ORM\Column(name: 'synced_kinds', type: Types::JSON, nullable: true)]
        private ?array $syncedKinds = null,
    ) {
    }

    /** @param list<SocialFeedKind> $syncedKinds */
    public static function completed(\DateTimeImmutable $at, array $syncedKinds): self
    {
        $values = array_map(static fn (SocialFeedKind $kind): string => $kind->value, $syncedKinds);

        return new self($at, $at, null, $values);
    }

    public function skipped(\DateTimeImmutable $at, PrivateFeedSyncSkip $skip): self
    {
        return new self($at, $this->syncedAt, $skip, $this->syncedKinds);
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

    /** @return list<SocialFeedKind> */
    public function syncedKinds(): array
    {
        return array_map(SocialFeedKind::from(...), $this->syncedKinds ?? []);
    }
}
