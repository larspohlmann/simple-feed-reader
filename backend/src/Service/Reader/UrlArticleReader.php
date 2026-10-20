<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\ArticleExtractor\ArticleExtractorInterface;
use App\Service\Reader\Model\EntryHintsModel;
use App\Service\Reader\Model\ExtractionResultModel;

final readonly class UrlArticleReader
{
    public function __construct(private ArticleExtractorInterface $extractor)
    {
    }

    public function read(string $url): ExtractionResultModel
    {
        return $this->extractor->extract($url, new EntryHintsModel());
    }
}
