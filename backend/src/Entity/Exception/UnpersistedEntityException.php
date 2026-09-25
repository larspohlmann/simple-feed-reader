<?php

declare(strict_types=1);

namespace App\Entity\Exception;

final class UnpersistedEntityException extends \LogicException
{
    public function __construct(string $entityClass)
    {
        parent::__construct(sprintf('%s has no id yet: it was never flushed.', $entityClass));
    }
}
