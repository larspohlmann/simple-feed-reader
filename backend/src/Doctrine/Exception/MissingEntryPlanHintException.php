<?php

declare(strict_types=1);

namespace App\Doctrine\Exception;

use App\Doctrine\EntryPlanHint;
use App\Doctrine\EntryPlanHintWalker;

/**
 * EntryPlanHintWalker was selected without a valid EntryPlanHint on the same
 * query — a caller wiring bug, never a legitimate "no hint" state.
 */
final class MissingEntryPlanHintException extends \LogicException
{
    public function __construct()
    {
        parent::__construct(EntryPlanHintWalker::class . ' requires a valid ' . EntryPlanHint::class . ' hint.');
    }
}
