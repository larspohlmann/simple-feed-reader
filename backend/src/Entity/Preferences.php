<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DigestCadence;
use App\Enum\DigestFormat;
use App\Enum\MagazineStyle;
use App\Repository\PreferencesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row of per-account settings. Created by the User constructor rather than
 * by each caller, so no account-creation path can forget it.
 */
#[ORM\Entity(repositoryClass: PreferencesRepository::class)]
#[ORM\Table(name: 'user_preferences')]
#[ORM\UniqueConstraint(name: 'uniq_preferences_user', columns: ['user_id'])]
final class Preferences
{
    public const string TARGET_ID_PATTERN = '/^[a-z0-9-]{1,32}$/';

    public const int MAX_HIDDEN_SHARE_TARGETS = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'preferences')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Off by default and presented as experimental: scraped extraction breaks whenever the target page changes. */
    #[ORM\Column(options: ['default' => false])]
    private bool $scrapeFallbackEnabled = false;

    #[ORM\Column(name: 'magazine_style', length: 10, enumType: MagazineStyle::class, options: ['default' => 'boxed'])]
    private MagazineStyle $magazineStyle = MagazineStyle::Boxed;

    #[ORM\Column(name: 'digest_enabled', options: ['default' => false])]
    private bool $digestEnabled = false;

    #[ORM\Column(name: 'digest_cadence', length: 10, enumType: DigestCadence::class, options: ['default' => 'daily'])]
    private DigestCadence $digestCadence = DigestCadence::Daily;

    #[ORM\Column(name: 'digest_format', length: 10, enumType: DigestFormat::class, options: ['default' => 'html'])]
    private DigestFormat $digestFormat = DigestFormat::Html;

    /** The local hour (0–23) the digest is sent, interpreted in the instance timezone. */
    #[ORM\Column(name: 'digest_send_hour', type: Types::SMALLINT, options: ['default' => 8])]
    private int $digestSendHour = 8;

    /** ISO-8601 weekday (1=Mon … 7=Sun); only meaningful for the weekly cadence. */
    #[ORM\Column(name: 'digest_weekday', type: Types::SMALLINT, options: ['default' => 1])]
    private int $digestWeekday = 1;

    /** Naive UTC. Null until the digest is first enabled; the "since" marker. */
    #[ORM\Column(name: 'digest_last_sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $digestLastSentAt = null;

    /** Naive UTC. Null until the account answers the one-time passkey enrolment offer, which then never shows again. */
    #[ORM\Column(name: 'passkey_offer_answered_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $passkeyOfferAnsweredAt = null;

    #[ORM\Column(name: 'sharing_enabled', options: ['default' => true])]
    private bool $sharingEnabled = true;

    /**
     * Nullable so the column could be added to existing rows; null reads as nothing hidden.
     *
     * @var list<string>|null
     */
    #[ORM\Column(name: 'hidden_share_targets', type: Types::JSON, nullable: true)]
    private ?array $hiddenShareTargets = null;

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

    public function getDigestFormat(): DigestFormat
    {
        return $this->digestFormat;
    }

    public function setDigestFormat(DigestFormat $digestFormat): void
    {
        $this->digestFormat = $digestFormat;
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

    public function isSharingEnabled(): bool
    {
        return $this->sharingEnabled;
    }

    public function setSharingEnabled(bool $sharingEnabled): void
    {
        $this->sharingEnabled = $sharingEnabled;
    }

    /** @return list<string> */
    public function getHiddenShareTargets(): array
    {
        return $this->hiddenShareTargets ?? [];
    }

    /**
     * Drops malformed and repeated ids and keeps the first MAX_HIDDEN_SHARE_TARGETS, for the API and a restore alike.
     *
     * @param list<string> $hiddenShareTargets
     */
    public function setHiddenShareTargets(array $hiddenShareTargets): void
    {
        $wellFormed = array_filter(
            $hiddenShareTargets,
            static fn (string $id): bool => preg_match(self::TARGET_ID_PATTERN, $id) === 1,
        );
        $unique = array_values(array_unique($wellFormed));
        $this->hiddenShareTargets = \array_slice($unique, 0, self::MAX_HIDDEN_SHARE_TARGETS);
    }
}
