<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\ProfileRunDistiller;

use App\Service\Recommendation\Profile\Model\ProfileDistillationOutcomeModel;
use App\Service\Recommendation\Profile\Pass\ProfileTick;

/** A profile run's one recorded model call: the history in, a short profile out; storing it is the caller's. */
interface ProfileRunDistillerInterface
{
    public function distill(ProfileTick $tick): ProfileDistillationOutcomeModel;
}
