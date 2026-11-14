<?php

declare(strict_types=1);

namespace App\Service\Foundation\Text;

use League\CommonMark\ConverterInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MarkdownHtml
{
    public function __construct(
        #[Autowire(service: 'app.untrusted_markdown_converter')]
        private ConverterInterface $converter,
    ) {
    }

    public function of(string $markdown): string
    {
        return $this->converter->convert($markdown)->getContent();
    }
}
