<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

trait KeyedByUser
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
