<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InclusionSurface;
use App\Enum\SocialNetwork;
use App\Repository\NetworkExclusionRepository;
use Doctrine\ORM\Mapping as ORM;

/** A social network whose feeds the user keeps out of one surface; a network without a row counts. */
#[ORM\Entity(repositoryClass: NetworkExclusionRepository::class)]
#[ORM\Table(name: 'network_exclusion')]
#[ORM\Index(name: 'idx_network_exclusion_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_network_exclusion', columns: ['user_id', 'network', 'surface'])]
final class NetworkExclusion
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: SocialNetwork::class)]
    private SocialNetwork $network;

    #[ORM\Column(length: 16, enumType: InclusionSurface::class)]
    private InclusionSurface $surface;

    public function __construct(User $user, SocialNetwork $network, InclusionSurface $surface)
    {
        $this->user = $user;
        $this->network = $network;
        $this->surface = $surface;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getNetwork(): SocialNetwork
    {
        return $this->network;
    }

    public function getSurface(): InclusionSurface
    {
        return $this->surface;
    }
}
