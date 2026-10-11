<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Model;

final readonly class SharedLinksModel
{
    /** @param list<string> $urls in post order, each once */
    public function __construct(
        public array $urls,
        public string $linkedHtml,
    ) {
    }
}
