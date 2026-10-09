<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Html\Support\PastedTextBreaks;
use App\Service\Text\Support\PlainTextBody;

final class FeedBodyHtml
{
    public static function of(?string $body): ?string
    {
        $html = PlainTextBody::asHtml($body);

        return $html === null ? null : PastedTextBreaks::inHtml($html);
    }

    private function __construct()
    {
    }
}
