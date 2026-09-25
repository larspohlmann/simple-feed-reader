<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\UnpersistedEntityException;

trait PersistedId
{
    abstract public function getId(): ?int;

    public function requireId(): int
    {
        return $this->getId() ?? throw new UnpersistedEntityException(static::class);
    }
}
