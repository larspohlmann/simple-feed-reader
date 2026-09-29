<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/** Reads the type off a PHPDoc tag; brackets may span lines, and `|`, `&` or `:` join a type across a space. */
final readonly class PhpDocTypeReader
{
    private const array TYPE_TAGS = [
        'param', 'param-out', 'return', 'var', 'throws', 'extends', 'implements', 'use', 'mixin', 'method',
        'property', 'property-read', 'property-write', 'template', 'template-covariant', 'template-contravariant',
        'type', 'import-type', 'assert', 'assert-if-true', 'assert-if-false', 'self-out', 'this-out',
        'require-extends', 'require-implements', 'sealed',
    ];

    private const string NOTHING = '~^~';

    private const string VARIABLE = '~^(?:\.\.\.)?&?\$\w+~';

    private const string TEMPLATE_NAME = '~^\S+\s+(?:of|as)\s+~';

    private const array BEFORE_TYPE = [
        'method' => '~^static\s+~',
        'template' => self::TEMPLATE_NAME,
        'template-covariant' => self::TEMPLATE_NAME,
        'template-contravariant' => self::TEMPLATE_NAME,
        'type' => '~^\w+\s*=?\s*~',
        'import-type' => '~^\S+\s+from\s+~',
    ];

    private const array AFTER_TYPE = [
        'param' => self::VARIABLE,
        'param-out' => self::VARIABLE,
        'var' => self::VARIABLE,
        'property' => self::VARIABLE,
        'property-read' => self::VARIABLE,
        'property-write' => self::VARIABLE,
        'assert' => self::VARIABLE,
        'assert-if-true' => self::VARIABLE,
        'assert-if-false' => self::VARIABLE,
        'method' => '~^\w+\([^)]*\)~',
        'import-type' => '~^as\s+\S+~',
    ];

    public function reads(string $tag): bool
    {
        return \in_array($tag, self::TYPE_TAGS, true);
    }

    public function read(string $tag, string $argument): PhpDocReading
    {
        $afterType = self::scan(self::textAfter(self::BEFORE_TYPE[$tag] ?? self::NOTHING, trim($argument)));
        if ($afterType->isOpen()) {
            return $afterType;
        }
        $description = self::textAfter(self::AFTER_TYPE[$tag] ?? self::NOTHING, $afterType->description);

        return PhpDocReading::closed($description);
    }

    private static function scan(string $text): PhpDocReading
    {
        $depth = 0;
        $position = 0;
        $length = strlen($text);
        while ($position < $length && !self::endsTypeAt($text, $position, $depth)) {
            $depth = self::depthAfter($text, $position, $depth);
            $position = self::afterQuoted($text, $position) + 1;
        }

        return $depth > 0 ? PhpDocReading::open($depth) : PhpDocReading::closed(trim(substr($text, $position)));
    }

    private static function endsTypeAt(string $text, int $position, int $depth): bool
    {
        return 0 === $depth && ctype_space($text[$position]) && !self::joinsAt($text, $position);
    }

    private static function joinsAt(string $text, int $position): bool
    {
        $before = substr(rtrim(substr($text, 0, $position)), -1);
        $after = substr(ltrim(substr($text, $position)), 0, 1);

        return \in_array($before, ['|', '&', ':'], true) || \in_array($after, ['|', '&'], true);
    }

    private static function depthAfter(string $text, int $position, int $depth): int
    {
        $character = $text[$position];
        if (str_contains('{<([', $character)) {
            return $depth + 1;
        }
        $isArrow = '>' === $character && $position > 0 && '-' === $text[$position - 1];
        if (str_contains('}>)]', $character) && !$isArrow) {
            return max(0, $depth - 1);
        }

        return $depth;
    }

    private static function afterQuoted(string $text, int $position): int
    {
        $quote = $text[$position];
        if ('"' !== $quote && "'" !== $quote) {
            return $position;
        }
        $closing = strpos($text, $quote, $position + 1);

        return false === $closing ? strlen($text) : $closing;
    }

    private static function textAfter(string $pattern, string $text): string
    {
        return 1 === preg_match($pattern, $text, $match) ? ltrim(substr($text, strlen($match[0]))) : $text;
    }
}
