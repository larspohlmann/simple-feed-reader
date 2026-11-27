<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LabelRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LabelRepository::class)]
#[ORM\Table(name: 'label')]
#[ORM\UniqueConstraint(name: 'uniq_label_user_name', columns: ['user_id', 'name'])]
final class Label
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** `utf8mb4_0900_bin`: byte-exact and NO PAD, like tag names. */
    #[ORM\Column(length: 60, options: ['collation' => 'utf8mb4_0900_bin'])]
    private string $name;

    public function __construct(User $user, string $name)
    {
        $this->user = $user;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }
}
