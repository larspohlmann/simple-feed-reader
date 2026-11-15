<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Language\Support;

use App\Service\Foundation\Text\Support\EntryPlainText;
use App\Service\Foundation\Text\Support\WordBoundary;

final class PreviewText
{
    private const int DESCRIPTION_LENGTH = 300;

    public static function of(string $title, ?string $description): string
    {
        return $title . "\n" . WordBoundary::cut(EntryPlainText::of($description) ?? '', self::DESCRIPTION_LENGTH);
    }

    private function __construct()
    {
    }
}
