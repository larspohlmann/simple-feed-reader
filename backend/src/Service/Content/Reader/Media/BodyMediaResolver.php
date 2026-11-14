<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\Media;

use App\Service\Content\Reader\Media\Model\ArticleMediaModel;
use App\Service\Content\Reader\Media\Sibling\SiblingMediaExtender;

final readonly class BodyMediaResolver
{
    public function __construct(
        private StreamLocationResolver $streamLocations,
        private SiblingMediaExtender $siblings,
    ) {
    }

    public function resolveForBody(ArticleMediaModel $declared, string $pageHtml): ArticleMediaModel
    {
        return $this->siblings->extend($declared, $this->streamLocations->resolve($declared), $pageHtml);
    }
}
