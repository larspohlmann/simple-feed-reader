<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PreferencesRepository;
use App\Service\Mail\Digest\DigestCadence;
use App\Service\Reader\MagazineStyle;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row of per-account settings. Created by the User constructor rather than
 * by each caller, so no account-creation path can forget it.
 */
#[ORM\Entity(repositoryClass: PreferencesRepository::class)]
#[ORM\Table(name: 'user_preferences')]
#[ORM\UniqueConstraint(name: 'uniq_preferences_user', columns: ['user_id'])]
class Preferences
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'preferences')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * Whether feed discovery may fall back to scraping a plain HTML page.
     * Off by default: extraction quality depends entirely on the target page
     * and can break whenever that page changes, so the feature is opt-in and
     * presented as experimental.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $scrapeFallbackEnabled = false;

    /** Boxed by default: what every existing account already sees (#723). */
    #[ORM\Column(name: 'magazine_style', length: 10, enumType: MagazineStyle::class, options: ['default' => 'boxed'])]
    private MagazineStyle $magazineStyle = MagazineStyle::Boxed;

    #[ORM\Column(name: 'digest_enabled', options: ['default' => false])]
    private bool $digestEnabled = false;

    #[ORM\Column(name: 'digest_cadence', length: 10, enumType: DigestCadence::class, options: ['default' => 'daily'])]
    private DigestCadence $digestCadence = DigestCadence::Daily;

    /** The local hour (0–23) the digest is sent, interpreted in the instance timezone. */
    #[ORM\Column(name: 'digest_send_hour', type: Types::SMALLINT, options: ['default' => 8])]
    private int $digestSendHour = 8;

    /** ISO-8601 weekday (1=Mon … 7=Sun); only meaningful for the weekly cadence. */
    #[ORM\Column(name: 'digest_weekday', type: Types::SMALLINT, options: ['default' => 1])]
    private int $digestWeekday = 1;

    /** Naive UTC. Null until the digest is first enabled; the "since" marker. */
    #[ORM\Column(name: 'digest_last_sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $digestLastSentAt = null;

    /**
     * Naive UTC. Null until the account answers the one-time passkey
     * enrolment offer (#624); once set, the offer must never show again.
     */
    #[ORM\Column(name: 'passkey_offer_answered_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $passkeyOfferAnsweredAt = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isScrapeFallbackEnabled(): bool
    {
        return $this->scrapeFallbackEnabled;
    }

    public function setScrapeFallbackEnabled(bool $scrapeFallbackEnabled): void
    {
        $this->scrapeFallbackEnabled = $scrapeFallbackEnabled;
    }

    public function getMagazineStyle(): MagazineStyle
    {
        return $this->magazineStyle;
    }

    public function setMagazineStyle(MagazineStyle $magazineStyle): void
    {
        $this->magazineStyle = $magazineStyle;
    }

    public function isDigestEnabled(): bool
    {
        return $this->digestEnabled;
    }

    public function setDigestEnabled(bool $digestEnabled): void
    {
        $this->digestEnabled = $digestEnabled;
    }

    public function getDigestCadence(): DigestCadence
    {
        return $this->digestCadence;
    }

    public function setDigestCadence(DigestCadence $digestCadence): void
    {
        $this->digestCadence = $digestCadence;
    }

    public function getDigestSendHour(): int
    {
        return $this->digestSendHour;
    }

    public function setDigestSendHour(int $digestSendHour): void
    {
        $this->digestSendHour = $digestSendHour;
    }

    public function getDigestWeekday(): int
    {
        return $this->digestWeekday;
    }

    public function setDigestWeekday(int $digestWeekday): void
    {
        $this->digestWeekday = $digestWeekday;
    }

    public function getDigestLastSentAt(): ?\DateTimeImmutable
    {
        return $this->digestLastSentAt;
    }

    public function setDigestLastSentAt(?\DateTimeImmutable $digestLastSentAt): void
    {
        $this->digestLastSentAt = $digestLastSentAt;
    }

    public function getPasskeyOfferAnsweredAt(): ?\DateTimeImmutable
    {
        return $this->passkeyOfferAnsweredAt;
    }

    /**
     * Idempotent by the caller's contract (see PasskeyOffer::markAnswered),
     * not here: Preferences holds state, it does not decide whether a write
     * is allowed to happen.
     */
    public function markPasskeyOfferAnswered(\DateTimeImmutable $at): void
    {
        $this->passkeyOfferAnsweredAt = $at;
    }
}
