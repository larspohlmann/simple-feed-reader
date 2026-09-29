<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\ShouldNotHappenException;

/**
 * CLAUDE.md's "one line, three at the absolute most": no comment block carries more than three lines of prose.
 *
 * @implements Rule<FileNode>
 */
final readonly class CommentBlockLengthRule implements Rule
{
    private const int MAX_PROSE_LINES = 3;

    private const string MESSAGE = 'This comment has %d lines of prose; CLAUDE.md allows three at the absolute most. '
        . 'Rename, extract, or move the reasoning to docs/ or the commit message.';

    /** @param list<string> $unsweptFiles paths relative to backend/; overridable only for the rule's own test */
    public function __construct(
        private CommentBlocks $commentBlocks,
        private array $unsweptFiles = UnsweptCommentFiles::FILES,
    ) {
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $file = $scope->getFile();
        if (\in_array(self::relativePath($file), $this->unsweptFiles, true)) {
            return [];
        }

        $overlong = array_filter(
            $this->commentBlocks->in(self::contentsOf($file)),
            static fn (CommentBlock $block): bool => $block->proseLines > self::MAX_PROSE_LINES,
        );

        return array_values(array_map(self::error(...), $overlong));
    }

    private static function relativePath(string $file): string
    {
        $backend = dirname(__DIR__, 2) . '/';

        return str_starts_with($file, $backend) ? substr($file, strlen($backend)) : $file;
    }

    private static function contentsOf(string $file): string
    {
        $contents = file_get_contents($file);
        if (false === $contents) {
            throw new ShouldNotHappenException(sprintf('PHPStan analyses %s but cannot read it.', $file));
        }

        return $contents;
    }

    private static function error(CommentBlock $block): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(self::MESSAGE, $block->proseLines))
            ->identifier('simpleFeedReader.commentBlockLength')
            ->line($block->firstLine)
            ->build();
    }
}
