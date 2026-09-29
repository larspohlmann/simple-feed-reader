<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/** Counts a block's prose lines: a PHPDoc type or a tool directive is not prose, a description or a reason is. */
final readonly class CommentProse
{
    private const string TAG = '~^(?:\{?@(?<tag>[\w-]+)\}?|phpcs:\w+)(?<argument>.*)$~is';

    private const string TOOL_PREFIX = '~^(?:phpstan|psalm)-~';

    private const string DIRECTIVE_ARGUMENT = '~^(?:\([^)]*\)|[^\s,]+(?:\s*,\s*[^\s,]+)*)~';

    private const array BARE_DIRECTIVES = [
        'api', 'codecoverageignore', 'codecoverageignoreend', 'codecoverageignorestart', 'deprecated', 'final',
        'immutable', 'impure', 'infection-ignore-all', 'inheritdoc', 'internal', 'pure', 'todo',
    ];

    public function __construct(private PhpDocTypeReader $typeReader)
    {
    }

    /**
     * A type whose brackets span lines is read as one statement: only its last line can carry a description.
     *
     * @param list<string> $lines a block's lines with the comment markers stripped
     */
    public function linesIn(array $lines): int
    {
        $proseLines = 0;
        $statement = '';
        foreach ($lines as $line) {
            $statement = ltrim($statement . "\n" . $line);
            $reading = $this->read($statement);
            if ($reading->isOpen() && '' !== $line) {
                continue;
            }
            $proseLines += $reading->isProse() ? 1 : 0;
            $statement = '';
        }

        return $proseLines;
    }

    private function read(string $statement): PhpDocReading
    {
        if (1 !== preg_match(self::TAG, $statement, $match)) {
            return PhpDocReading::closed($statement);
        }
        $tag = self::textAfter(self::TOOL_PREFIX, strtolower($match['tag']));
        if ($this->typeReader->reads($tag)) {
            return $this->typeReader->read($tag, $match['argument']);
        }

        return PhpDocReading::closed(self::reasonAfter($tag, trim($match['argument'])));
    }

    private static function reasonAfter(string $tag, string $argument): string
    {
        $reason = \in_array($tag, self::BARE_DIRECTIVES, true)
            ? $argument
            : self::textAfter(self::DIRECTIVE_ARGUMENT, $argument);

        return trim(ltrim($reason, '-'));
    }

    private static function textAfter(string $pattern, string $text): string
    {
        return 1 === preg_match($pattern, $text, $match) ? ltrim(substr($text, strlen($match[0]))) : $text;
    }
}
