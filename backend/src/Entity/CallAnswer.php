<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CallVerdict;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** What came back for one call and how it ended; RecordedCall writes it straight to the database. */
#[ORM\Embeddable]
final class CallAnswer
{
    public const int LONGTEXT_LENGTH = 4_294_967_295;

    #[ORM\Column(type: Types::TEXT, length: self::LONGTEXT_LENGTH)]
    private string $responseText = '';

    /** Null while the call is still streaming. */
    #[ORM\Column(length: 24, nullable: true, enumType: CallVerdict::class)]
    // @phpstan-ignore property.unusedType (only the repository's SQL and Doctrine's hydration assign it)
    private ?CallVerdict $verdict = null;

    /** Every byte the provider sent: a reasoning model can send megabytes while $responseText stays empty. */
    #[ORM\Column(options: ['default' => 0])]
    private int $wireBytes = 0;

    /** When the call settled (any verdict). Null while streaming. */
    #[ORM\Column(nullable: true)]
    // @phpstan-ignore property.unusedType (only the repository's SQL and Doctrine's hydration assign it)
    private ?\DateTimeImmutable $finishedAt = null;

    public function getResponseText(): string
    {
        return $this->responseText;
    }

    public function getVerdict(): ?CallVerdict
    {
        return $this->verdict;
    }

    public function getWireBytes(): int
    {
        return $this->wireBytes;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }
}
