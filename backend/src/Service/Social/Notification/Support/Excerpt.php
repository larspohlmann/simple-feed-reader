<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\Support;

use App\Service\Foundation\Text\Support\ClippedText;
use App\Service\Foundation\Text\Support\PlainText;

final class Excerpt
{
    private const int LENGTH = 280;

    public static function cutText(?string $text): ?string
    {
        $trimmed = trim($text ?? '');

        return $trimmed === '' ? null : ClippedText::cutScrubbedText($trimmed, self::LENGTH);
    }

    public static function cutHtml(?string $html): ?string
    {
        return self::cutText(PlainText::fromHtmlBlocks($html));
    }

    private function __construct()
    {
    }
}
