<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;

/** @template T of object */
trait UserSettingsRowLookup
{
    /** @return T */
    public function forUser(User $user): object
    {
        return $this->find($user->requireId()) ?? throw new \LogicException(\sprintf(
            'User %d has no %s row; UserSettingsRowsListener creates every one with the account.',
            $user->requireId(),
            $this->getClassName(),
        ));
    }
}
