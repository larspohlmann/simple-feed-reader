<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Parser\Support;

use App\Service\Foundation\Html\Support\PastedTextBreaks;
use App\Service\Foundation\Text\Support\PlainTextBody;

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
