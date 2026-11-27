<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\InterestTermExtractor;

use App\Service\Curation\Recommendation\Stream\Model\InterestTermOutcomeModel;
use App\Service\Curation\Recommendation\Stream\Pass\InterestTermPass;

interface InterestTermExtractorInterface
{
    public function extractTerms(InterestTermPass $pass): InterestTermOutcomeModel;
}
