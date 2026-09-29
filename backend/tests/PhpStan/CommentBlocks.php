<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpToken;

/** A file's comment blocks: comment tokens with only whitespace between them are one block. */
final readonly class CommentBlocks
{
    public function __construct(private CommentProse $prose)
    {
    }

    /** @return list<CommentBlock> */
    public function in(string $code): array
    {
        $blocks = [];
        foreach (self::commentRuns($code) as $comments) {
            $blocks[] = new CommentBlock($comments[0]->line, $this->prose->linesIn(self::textLinesOf($comments)));
        }

        return $blocks;
    }

    /** @return list<non-empty-list<PhpToken>> */
    private static function commentRuns(string $code): array
    {
        $runs = [];
        $run = [];
        foreach (PhpToken::tokenize($code) as $token) {
            if ($token->is(T_WHITESPACE)) {
                continue;
            }
            if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                $run[] = $token;
                continue;
            }
            if ([] !== $run) {
                $runs[] = $run;
            }
            $run = [];
        }

        return [] === $run ? $runs : [...$runs, $run];
    }

    /**
     * @param list<PhpToken> $comments
     *
     * @return list<string>
     */
    private static function textLinesOf(array $comments): array
    {
        $lines = [];
        foreach ($comments as $comment) {
            $strip = str_starts_with($comment->text, '/*')
                ? self::withoutBlockMarkers(...)
                : self::withoutLineMarker(...);
            $lines = [...$lines, ...array_map($strip, explode("\n", $comment->text))];
        }

        return $lines;
    }

    private static function withoutLineMarker(string $line): string
    {
        return trim(ltrim(trim($line), '/#'));
    }

    private static function withoutBlockMarkers(string $line): string
    {
        $text = trim($line);
        if (str_starts_with($text, '/*')) {
            $text = substr($text, 2);
        }
        if (str_ends_with($text, '*/')) {
            $text = substr($text, 0, -2);
        }

        return trim(ltrim(trim($text), '*'));
    }
}
