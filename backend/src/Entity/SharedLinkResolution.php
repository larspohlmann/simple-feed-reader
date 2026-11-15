<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LinkResolution;
use App\Repository\SharedLinkResolutionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SharedLinkResolutionRepository::class)]
#[ORM\Table(name: 'shared_link_resolution')]
#[ORM\Index(name: 'idx_shared_link_resolution_due', columns: ['status', 'retry_at', 'shared_link_id'])]
final class SharedLinkResolution
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: SharedLink::class)]
    #[ORM\JoinColumn(name: 'shared_link_id', onDelete: 'CASCADE')]
    private SharedLink $sharedLink;

    #[ORM\Column(length: 10, enumType: LinkResolution::class)]
    private LinkResolution $status = LinkResolution::Pending;

    #[ORM\Column(name: 'resolved_url', length: LinkCardContent::URL_MAX, nullable: true)]
    private ?string $resolvedUrl = null;

    #[ORM\Column(name: 'resolved_url_hash', length: 64, nullable: true)]
    private ?string $resolvedUrlHash = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(name: 'retry_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $retryAt = null;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(SharedLink $sharedLink)
    {
        $this->sharedLink = $sharedLink;
    }

    public function getSharedLink(): SharedLink
    {
        return $this->sharedLink;
    }

    public function getStatus(): LinkResolution
    {
        return $this->status;
    }

    public function getResolvedUrl(): ?string
    {
        return $this->resolvedUrl;
    }

    public function getResolvedUrlHash(): ?string
    {
        return $this->resolvedUrlHash;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getRetryAt(): ?\DateTimeImmutable
    {
        return $this->retryAt;
    }
}
