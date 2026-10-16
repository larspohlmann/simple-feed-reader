<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RetentionSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/** The instance-wide retention windows, held in a single row (see InstanceSetting); no row means the defaults. */
#[ORM\Entity(repositoryClass: RetentionSettingRepository::class)]
#[ORM\Table(name: 'retention_setting')]
final class RetentionSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => RetentionWindows::DEFAULT_ENTRY_DAYS])]
    private int $entryRetentionDays = RetentionWindows::DEFAULT_ENTRY_DAYS;

    #[ORM\Column(options: ['default' => RetentionWindows::DEFAULT_SOCIAL_DAYS])]
    private int $socialRetentionDays = RetentionWindows::DEFAULT_SOCIAL_DAYS;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWindows(): RetentionWindows
    {
        return new RetentionWindows($this->entryRetentionDays, $this->socialRetentionDays);
    }

    public function apply(RetentionWindows $windows): void
    {
        $this->entryRetentionDays = $windows->entryDays;
        $this->socialRetentionDays = $windows->socialDays;
    }
}
