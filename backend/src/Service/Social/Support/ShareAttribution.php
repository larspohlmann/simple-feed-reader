<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Text\Support\HtmlAnchor;
use App\Service\Text\Support\HtmlEscape;

/** The trailing "Reposted by …" / "Boosted by …" line: no line without a name, no link without a web page. */
final class ShareAttribution
{
    public static function line(string $lead, ?string $name, ?string $profileUrl): string
    {
        if (null === $name) {
            return '';
        }
        $sharer = null === $profileUrl ? HtmlEscape::text($name) : HtmlAnchor::to($profileUrl, $name);

        return '<p>' . HtmlEscape::text($lead) . ' ' . $sharer . '</p>';
    }

    private function __construct()
    {
    }
}
