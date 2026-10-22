<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SocialNetwork;
use App\Repository\SavedSearchNetworkInclusionRepository;
use Doctrine\ORM\Mapping as ORM;

/** A social network whose feeds count in the user's saved searches; a network without a row is left out (#1742). */
#[ORM\Entity(repositoryClass: SavedSearchNetworkInclusionRepository::class)]
#[ORM\Table(name: 'saved_search_network_inclusion')]
#[ORM\Index(name: 'idx_saved_search_network_inclusion_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_saved_search_network_inclusion', columns: ['user_id', 'network'])]
final class SavedSearchNetworkInclusion
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
