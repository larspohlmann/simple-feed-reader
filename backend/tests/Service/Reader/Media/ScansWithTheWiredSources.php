<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\PageMediaScanner;
use App\Service\Reader\Media\RawPage;

trait ScansWithTheWiredSources
{
    private function scanner(): PageMediaScanner
    {
        self::bootKernel();
        $scanner = self::getContainer()->get(PageMediaScanner::class);
        self::assertInstanceOf(PageMediaScanner::class, $scanner);

        return $scanner;
    }

    private function scan(string $html, string $url): ArticleMedia
    {
        return $this->scanner()->scan(RawPage::parse($html, $url));
    }
}
