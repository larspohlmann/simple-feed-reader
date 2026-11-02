<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SocialNetwork;
use App\Repository\ForYouNetworkExclusionRepository;
use Doctrine\ORM\Mapping as ORM;

/** A social network whose feeds the user keeps out of For you; a network without a row counts (#1995). */
#[ORM\Entity(repositoryClass: ForYouNetworkExclusionRepository::class)]
#[ORM\Table(name: 'for_you_network_exclusion')]
#[ORM\Index(name: 'idx_for_you_network_exclusion_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_for_you_network_exclusion', columns: ['user_id', 'network'])]
final class ForYouNetworkExclusion
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

    public function __construct(User $user, SocialNetwork $network)
    {
        $this->user = $user;
        $this->network = $network;
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
}
