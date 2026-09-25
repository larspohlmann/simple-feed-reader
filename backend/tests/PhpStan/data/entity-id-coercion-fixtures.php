<?php

declare(strict_types=1);

namespace App\Tests\PhpStan\Data\EntityIdCoercion;

use App\Entity\User;

final class NotAnEntity
{
    public function getId(): ?int
    {
        return null;
    }
}

/** @param array{id: string} $row */
function coercions(User $user, NotAnEntity $dto, array $row): void
{
    $cast = (int) $user->getId();
    $defaulted = $user->getId() ?? 0;
    $fromRow = (int) $row['id'];
    $foreign = $dto->getId() ?? 0;
    $read = $user->requireId();
}
