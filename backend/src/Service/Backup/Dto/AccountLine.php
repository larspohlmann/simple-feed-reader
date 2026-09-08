<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Service\Reader\MagazineStyle;

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
            magazineStyle: LineFieldWithDefault::enum($line, 'magazineStyle', MagazineStyle::Boxed),
        );
    }
}
