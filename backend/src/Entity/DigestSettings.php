<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DigestCadence;
use App\Enum\DigestFormat;
use App\Repository\DigestSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One row per account, created with it by UserSettingsRowsListener. */
#[ORM\Entity(repositoryClass: DigestSettingsRepository::class)]
#[ORM\Table(name: 'user_digest_settings')]
final class DigestSettings
{
    use KeyedByUser;

    #[ORM\Column(options: ['default' => false])]
    private bool $enabled = false;

    #[ORM\Column(length: 10, enumType: DigestCadence::class, options: ['default' => 'daily'])]
    private DigestCadence $cadence = DigestCadence::Daily;

    #[ORM\Column(length: 10, enumType: DigestFormat::class, options: ['default' => 'html'])]
    private DigestFormat $format = DigestFormat::Html;

    /** The local hour (0–23) the digest is sent, interpreted in the instance timezone. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 8])]
    private int $sendHour = 8;

    /** ISO-8601 weekday (1=Mon … 7=Sun); only meaningful for the weekly cadence. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $weekday = 1;

    /** Naive UTC. Null until the digest is first enabled; the "since" marker. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSentAt = null;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getCadence(): DigestCadence
    {
        return $this->cadence;
    }

    public function setCadence(DigestCadence $cadence): void
    {
        $this->cadence = $cadence;
    }

    public function getFormat(): DigestFormat
    {
        return $this->format;
    }

    public function setFormat(DigestFormat $format): void
    {
        $this->format = $format;
    }

    public function getSendHour(): int
    {
        return $this->sendHour;
    }

    public function setSendHour(int $sendHour): void
    {
        $this->sendHour = $sendHour;
    }

    public function getWeekday(): int
    {
        return $this->weekday;
    }

    public function setWeekday(int $weekday): void
    {
        $this->weekday = $weekday;
    }

    public function getLastSentAt(): ?\DateTimeImmutable
    {
        return $this->lastSentAt;
    }

    public function setLastSentAt(?\DateTimeImmutable $lastSentAt): void
    {
        $this->lastSentAt = $lastSentAt;
    }
}
