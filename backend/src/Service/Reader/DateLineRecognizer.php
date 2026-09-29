<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\DependencyInjection\ProcessLifetimeState;
use App\Service\Reader\Factory\DateFormatterFactoryInterface;

#[ProcessLifetimeState('Formatters are immutable per locale and format')]
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
        $formatter = $this->dateFormatters[$locale][$style] ??= $this->formatterFactory->build($locale, $style);

        $position = 0;
        $timestamp = $formatter->parse($text, $position);

        // parse() reports the stop position in code points, so compare with
        // mb_strlen: a byte length rejects any date with a non-ASCII month (März).
        return $timestamp !== false && $position === mb_strlen($text);
    }
}
