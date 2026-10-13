<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

/** Which exchange reads a ticket's answer; the engine keys its exchange locator by the value. */
enum ResponseExchangeKind: string
{
    private const string FEED_ACCEPT =
        'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.1';

    case Feed = 'feed';

    public function accept(): string
    {
        return match ($this) {
            self::Feed => self::FEED_ACCEPT,
        };
    }

    public function sizeLimit(): ResponseSizeLimit
    {
        return match ($this) {
            self::Feed => ResponseSizeLimit::Feed,
        };
    }
}
