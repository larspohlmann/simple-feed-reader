<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PrivateFeedSyncSkip;
use App\Enum\SocialFeedKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * When an account's lists and feeds were last checked against the network, which kinds the last complete check
 * covered, and why the last one was skipped (#1666). A skip keeps the kinds: a kind's baseline waits for the first
 * complete answer that covers it.
 */
#[ORM\Embeddable]
final readonly class SyncStamp
{
    public function __construct(
        #[ORM\Column(name: 'last_checked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $checkedAt = null,
        #[ORM\Column(name: 'last_sync_skip', length: 24, nullable: true, enumType: PrivateFeedSyncSkip::class)]
        private ?PrivateFeedSyncSkip $skip = null,
        /** @var list<string>|null */
        #[ORM\Column(name: 'synced_kinds', type: Types::JSON, nullable: true)]
        private ?array $syncedKinds = null,
    ) {
    }

    public static function completed(\DateTimeImmutable $at): self
    {
        $values = array_map(
            static fn (SocialFeedKind $kind): string => $kind->value,
            SocialFeedKind::rememberedCollections(),
        );

        return new self($at, null, $values);
    }

    public function skipped(\DateTimeImmutable $at, PrivateFeedSyncSkip $skip): self
    {
        return new self($at, $skip, $this->syncedKinds);
    }

    public function checkedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function skip(): ?PrivateFeedSyncSkip
    {
        return $this->skip;
    }

    /** @return list<SocialFeedKind> */
    public function kindsNotYetSynced(): array
    {
        $synced = $this->syncedKinds ?? [];

        return array_values(array_filter(
            SocialFeedKind::rememberedCollections(),
            static fn (SocialFeedKind $kind): bool => !\in_array($kind->value, $synced, true),
        ));
    }
}
