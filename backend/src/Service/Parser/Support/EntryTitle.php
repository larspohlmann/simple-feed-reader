<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Parser\Model\ParsedTitleModel;
use App\Service\Text\Support\DerivedTitle;
use App\Service\Text\Support\PlainText;

final class EntryTitle
{
    public static function of(?string $feedTitle, ?string $bodyHtml): ParsedTitleModel
    {
        $title = PlainText::from($feedTitle);
        if ($title !== null) {
            return ParsedTitleModel::fromFeed($title);
        }

        $derived = DerivedTitle::from($bodyHtml);

        return $derived === null ? ParsedTitleModel::untitled() : ParsedTitleModel::derived($derived);
    }

    private function __construct()
    {
    }
}
