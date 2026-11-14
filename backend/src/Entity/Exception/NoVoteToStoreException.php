<?php

declare(strict_types=1);

namespace App\Entity\Exception;

final class NoVoteToStoreException extends \LogicException
{
    public function __construct()
    {
        parent::__construct('A vote is stored as up or down; taking a vote back deletes the row.');
    }
}
