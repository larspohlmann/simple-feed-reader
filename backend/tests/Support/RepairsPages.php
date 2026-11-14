<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Foundation\Html\Support\HtmlDocumentParser;
use App\Service\Content\Reader\PageRepair\PageRepairInterface;

trait RepairsPages
{
    private function htmlAfterRepair(PageRepairInterface $repair, string $bodyHtml): string
    {
        $document = HtmlDocumentParser::parse('<!doctype html><html lang="en"><body>' . $bodyHtml . '</body></html>');
        $repair->repairIn($document);

        return $document->saveHtml();
    }
}
