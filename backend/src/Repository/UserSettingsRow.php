<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;

final class UserSettingsRow
{
    /**
     * @template T of object
     *
     * @param T|null $row
     *
     * @return T
     */
    public static function required(?object $row, User $user): object
    {
        if (null === $row) {
            throw new \LogicException(\sprintf(
                'User %d has no settings row; UserSettingsRowsListener creates every one with the account.',
                $user->requireId(),
            ));
        }

        return $row;
    }

    private function __construct()
    {
    }
}
