<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Enum\MagazineStyle;
use App\Service\Backup\Support\LineField;
use App\Service\Backup\Support\LineFieldWithDefault;

/**
 * The account's own settings, exactly once per backup.
 */
final readonly class AccountLine
{
    public function __construct(
        public string $locale,
        public bool $scrapeFallbackEnabled,
        public MagazineStyle $magazineStyle,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): self
    {
        return new self(
            locale: LineField::string($line, 'locale'),
            scrapeFallbackEnabled: LineField::bool($line, 'scrapeFallbackEnabled'),
            // An unknown style falls back to default: restore over reject.
            magazineStyle: MagazineStyle::tryFrom(
                LineFieldWithDefault::string($line, 'magazineStyle', MagazineStyle::Boxed->value),
            ) ?? MagazineStyle::Boxed,
        );
    }
}
