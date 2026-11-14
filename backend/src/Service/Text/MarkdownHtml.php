<?php

declare(strict_types=1);

namespace App\Service\Text;

use League\CommonMark\CommonMarkConverter;

final readonly class MarkdownHtml
{
    private CommonMarkConverter $converter;

    public function __construct()
    {
        $this->converter = new CommonMarkConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    public function of(string $markdown): string
    {
        return $this->converter->convert($markdown)->getContent();
    }
}
