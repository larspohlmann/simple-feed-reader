<?php

declare(strict_types=1);

namespace App\Service\Library\Label;

use App\Entity\User;
use App\Repository\LabelRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Every writer that claims a label name holds this, or racing requests can each create "Work" and "work". */
final readonly class LabelNameLock
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LabelRepository $labels,
    ) {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function hold(User $user, \Closure $work): mixed
    {
        $this->entityManager->beginTransaction();
        try {
            $this->labels->lockLabelNames($user->requireId());
            $result = $work();
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        return $result;
    }
}
