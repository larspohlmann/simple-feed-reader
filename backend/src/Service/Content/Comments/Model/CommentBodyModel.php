<?php

declare(strict_types=1);

namespace App\Service\Content\Comments\Model;

final readonly class CommentBodyModel
{
    private function __construct(public CommentBodyFormat $format, public string $source)
    {
    }

    public static function fromHtml(string $html): self
    {
        return new self(CommentBodyFormat::Html, $html);
    }

    public static function fromMarkdown(string $markdown): self
    {
        return new self(CommentBodyFormat::Markdown, $markdown);
    }

    public static function fromPlainText(string $text): self
    {
        return new self(CommentBodyFormat::PlainText, $text);
    }
}
