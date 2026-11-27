<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\InterestTermExtractor;

use App\Service\Curation\Recommendation\Stream\Model\InterestTermOutcomeModel;
use App\Service\Curation\Recommendation\Stream\Pass\InterestTermPass;

/** An interest-term run's one recorded model call: the prose profile in, weighted terms out; storing them is the caller's. */
interface InterestTermExtractorInterface
{
    public function extractTerms(InterestTermPass $pass): InterestTermOutcomeModel;
}
