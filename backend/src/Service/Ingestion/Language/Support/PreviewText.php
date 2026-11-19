<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Language\Support;

use App\Entity\LinkCardContent;
use App\Service\Foundation\Text\Support\EntryPlainText;

/** The text a card's language is decided on: its title, then its description as plain text, cut as stored. */
final class PreviewText
{
    public static function fromTitleAndDescription(string $title, ?string $description): string
    {
        return self::joined($title, self::cutDescription($description));
    }

    public static function cutDescription(?string $description): string
    {
        return EntryPlainText::cut($description, LinkCardContent::DESCRIPTION_MAX);
    }

    public static function joined(string $title, string $descriptionText): string
    {
        return $title . "\n" . $descriptionText;
    }

    private function __construct()
    {
    }
}
