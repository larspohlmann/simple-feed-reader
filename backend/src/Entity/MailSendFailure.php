<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MailSendFailureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A persisted record of one failed outgoing-mail attempt (#882): recipient,
 * kind, explained transport message, and timestamp.
 */
#[ORM\Entity(repositoryClass: MailSendFailureRepository::class)]
#[ORM\Table(name: 'mail_send_failure')]
class MailSendFailure
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: MailKind::class)]
    private MailKind $kind;

    #[ORM\Column(length: 255)]
    private string $recipient;

    #[ORM\Column(type: Types::TEXT)]
    private string $errorDetail;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        MailKind $kind,
        string $recipient,
        string $errorDetail,
        \DateTimeImmutable $createdAt,
    ) {
        $this->kind = $kind;
        $this->recipient = $recipient;
        $this->errorDetail = $errorDetail;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): MailKind
    {
        return $this->kind;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function getErrorDetail(): string
    {
        return $this->errorDetail;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
