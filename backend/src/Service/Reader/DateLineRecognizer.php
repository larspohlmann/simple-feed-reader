<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\DependencyInjection\ProcessLifetimeState;
use App\Service\Reader\Factory\DateFormatterFactoryInterface;
use App\Service\Reader\Model\ParseOffsetUnit;

#[ProcessLifetimeState('Formatters are immutable per locale and format, and the offset unit per PHP build')]
final class DateLineRecognizer
{
    private const int DATE_LINE_MAX_CHARS = 48;
    private const array DATE_LINE_LOCALES = ['de', 'en_US', 'en_GB'];
    private const array DATE_LINE_STYLES = [
        \IntlDateFormatter::FULL,
        \IntlDateFormatter::LONG,
        \IntlDateFormatter::MEDIUM,
    ];

    /** @var array<string, array<int, \IntlDateFormatter>> */
    private array $dateFormatters = [];

    private ?ParseOffsetUnit $offsetUnit = null;

    public function __construct(private readonly DateFormatterFactoryInterface $formatterFactory)
    {
    }

    /**
     * A stand-alone publication date. ICU supplies the German and English forms,
     * so none is hand-listed; strict full-string parsing with a length cap and a
     * required digit keep a bare month, a lone year or a sentence out.
     */
    public function isDateLine(string $text): bool
    {
        if (mb_strlen($text) > self::DATE_LINE_MAX_CHARS || preg_match('/\d/', $text) !== 1) {
            return false;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return true;
        }

        return array_any(
            self::DATE_LINE_LOCALES,
            fn (string $locale): bool => array_any(
                self::DATE_LINE_STYLES,
                fn (int $style): bool => $this->consumesWholeStringAsDate($text, $locale, $style),
            ),
        );
    }

    private function consumesWholeStringAsDate(string $text, string $locale, int $style): bool
    {
        $position = 0;
        $timestamp = $this->formatter($locale, $style)->parse($text, $position);

        return $timestamp !== false && $position === $this->offsetUnit()->lengthOf($text);
    }

    private function offsetUnit(): ParseOffsetUnit
    {
        return $this->offsetUnit ??= ParseOffsetUnit::reportedBy($this->formatter('de', \IntlDateFormatter::LONG));
    }

    private function formatter(string $locale, int $style): \IntlDateFormatter
    {
        return $this->dateFormatters[$locale][$style] ??= $this->formatterFactory->build($locale, $style);
    }
}
