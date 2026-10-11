<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Entity\Headline;
use App\Service\Text\Support\DerivedTitle;
use App\Service\Text\Support\PlainText;

final class EntryTitle
{
    public static function of(?string $feedTitle, ?string $bodyHtml): Headline
    {
        $title = PlainText::from($feedTitle);
        if ($title !== null) {
            return Headline::fromFeed($title);
        }

        $derived = DerivedTitle::from($bodyHtml);

        return $derived === null ? Headline::untitledPost() : Headline::derived($derived);
    }

    private function __construct()
    {
    }
}
