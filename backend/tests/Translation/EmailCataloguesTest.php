<?php

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Enum\SupportedLocale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class EmailCataloguesTest extends TestCase
{
    private const string LINK_PLACEHOLDER = '%(?:link|review_url)%';

    /** @return iterable<string, array{string}> */
    public static function translatedLocales(): iterable
    {
        foreach (SupportedLocale::ALL as $locale) {
            if (SupportedLocale::ENGLISH !== $locale) {
                yield $locale => [$locale];
            }
        }
    }

    #[DataProvider('translatedLocales')]
    public function testTheCatalogueHoldsExactlyTheEnglishKeys(string $locale): void
    {
        self::assertSame(
            array_keys(self::flattened(SupportedLocale::ENGLISH)),
            array_keys(self::flattened($locale)),
        );
    }

    #[DataProvider('translatedLocales')]
    public function testEveryMessageKeepsTheEnglishPlaceholders(string $locale): void
    {
        $translated = self::flattened($locale);

        foreach (self::flattened(SupportedLocale::ENGLISH) as $key => $english) {
            self::assertSame(
                self::placeholders($english),
                self::placeholders($translated[$key] ?? ''),
                "{$locale}: {$key}",
            );
        }
    }

    #[DataProvider('translatedLocales')]
    public function testALinkPlaceholderSitsAloneOnItsLine(string $locale): void
    {
        foreach (self::flattened($locale) as $key => $message) {
            if (!str_ends_with($key, '.body')) {
                continue;
            }

            foreach (self::linesCarryingALink($message) as $line) {
                self::assertMatchesRegularExpression('/^' . self::LINK_PLACEHOLDER . '$/', $line, "{$locale}: {$key}");
            }
        }
    }

    /** @return list<string> */
    private static function linesCarryingALink(string $message): array
    {
        return array_values(array_filter(
            explode("\n", $message),
            static fn (string $line): bool => 1 === preg_match('/' . self::LINK_PLACEHOLDER . '/', $line),
        ));
    }

    /** @return array<string, string> */
    private static function flattened(string $locale): array
    {
        $catalogue = Yaml::parseFile(\dirname(__DIR__, 2) . "/translations/emails.{$locale}.yaml");
        self::assertIsArray($catalogue);

        return self::flatten($catalogue, '');
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<string, string>
     */
    private static function flatten(array $node, string $prefix): array
    {
        $flat = [];
        foreach ($node as $name => $value) {
            $key = '' === $prefix ? (string) $name : "{$prefix}.{$name}";
            if (\is_array($value)) {
                $flat += self::flatten($value, $key);
            } else {
                self::assertIsString($value, $key);
                $flat[$key] = $value;
            }
        }

        return $flat;
    }

    /** @return list<string> */
    private static function placeholders(string $message): array
    {
        preg_match_all('/%[a-z_]+%/', $message, $matches);
        $placeholders = $matches[0];
        sort($placeholders);

        return $placeholders;
    }
}
