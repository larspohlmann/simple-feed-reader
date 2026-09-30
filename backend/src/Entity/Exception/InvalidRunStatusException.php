<?php

declare(strict_types=1);

namespace App\Entity\Exception;

use App\Enum\RunStatus;

final class InvalidRunStatusException extends \LogicException
{
    public function __construct(string $operation, RunStatus $status)
    {
        parent::__construct(sprintf(
            'Cannot %s a recommendation run from status "%s".',
            $operation,
            $status->value,
        ));
    }
}
