<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\Exception;

use App\Service\Content\Reader\Model\ExtractionFailure;

final class ArticleNotExtractedException extends \RuntimeException
{
    public function __construct(public readonly ExtractionFailure $failure)
    {
        parent::__construct(sprintf('The article was not extracted: %s.', $failure->value));
    }
}
