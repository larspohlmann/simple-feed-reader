<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\Reader\Media;

use App\Service\Content\Reader\Media\Model\ArticleMediaModel;
use App\Service\Content\Reader\Media\Model\RawPageModel;
use App\Service\Content\Reader\Media\PageMediaScanner;

trait ScansWithTheWiredSources
{
    private function scanner(): PageMediaScanner
    {
        self::bootKernel();
        $scanner = self::getContainer()->get(PageMediaScanner::class);
        self::assertInstanceOf(PageMediaScanner::class, $scanner);

        return $scanner;
    }

    private function scan(string $html, string $url): ArticleMediaModel
    {
        return $this->scanner()->scan(RawPageModel::parse($html, $url));
    }
}
