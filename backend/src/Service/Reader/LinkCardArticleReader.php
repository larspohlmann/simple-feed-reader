<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\LinkCardContent;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Reader\ArticleExtractor\ArticleExtractorInterface;
use App\Service\Reader\Model\EntryHintsModel;
use App\Service\Reader\Model\ExtractionResultModel;

final readonly class LinkCardArticleReader
{
    public function __construct(private ArticleExtractorInterface $extractor)
    {
    }

    public function read(LinkCardContent $card): ExtractionResultModel
    {
        return $this->extractor->extract($card->url, new EntryHintsModel(title: $card->title));
    }

    public function lead(LinkCardContent $card): ?DeclaredImageModel
    {
        return $card->imageUrl === null ? null : new DeclaredImageModel($card->imageUrl);
    }
}
