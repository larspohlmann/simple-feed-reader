<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SharingSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One row per account, created with it by UserSettingsRowsListener. */
#[ORM\Entity(repositoryClass: SharingSettingsRepository::class)]
#[ORM\Table(name: 'user_sharing_settings')]
final class SharingSettings
{
    use KeyedByUser;

    public const string TARGET_ID_PATTERN = '/^[a-z0-9-]{1,32}$/';

    public const int MAX_HIDDEN_TARGETS = 64;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $hiddenTargets = [];

    #[ORM\Column(name: 'remember_shares', options: ['default' => true])]
    private bool $rememberingShares = true;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /** @return list<string> */
    public function getHiddenTargets(): array
    {
        return $this->hiddenTargets;
    }

    /**
     * Drops malformed and repeated ids and keeps the first MAX_HIDDEN_TARGETS, for the API and a restore alike.
     *
     * @param list<string> $hiddenTargets
     */
    public function setHiddenTargets(array $hiddenTargets): void
    {
        $wellFormed = array_filter(
            $hiddenTargets,
            static fn (string $id): bool => preg_match(self::TARGET_ID_PATTERN, $id) === 1,
        );
        $this->hiddenTargets = \array_slice(array_unique($wellFormed), 0, self::MAX_HIDDEN_TARGETS);
    }

    public function isRememberingShares(): bool
    {
        return $this->rememberingShares;
    }

    public function setRememberingShares(bool $rememberingShares): void
    {
        $this->rememberingShares = $rememberingShares;
    }

    public function recordsShares(): bool
    {
        return $this->enabled && $this->rememberingShares;
    }
}
