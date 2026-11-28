<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\YourStreamWordBuilder;

use App\Service\Curation\YourStream\Model\InterestInputsModel;
use App\Service\Curation\YourStream\Model\StreamWordModel;
use App\Service\Curation\YourStream\Pass\RaritySweepPass;

interface YourStreamWordBuilderInterface
{
    /** @return list<StreamWordModel> */
    public function buildWords(InterestInputsModel $inputs, RaritySweepPass $pass): array;
}
