<?php

declare(strict_types=1);

namespace App\Service\Sanitize;

use OpenTelemetry\API\Instrumentation\WithSpan;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Sanitizes third-party article HTML before storage, configured in code so tests need no container. SECURITY: the
 * only barrier between feed HTML and an SPA holding its JWT in localStorage; a stored XSS here is account takeover.
 */
final readonly class EntrySanitizer
{
    private const int MAX_INPUT_LENGTH = 150_000;

    private HtmlSanitizerInterface $sanitizer;

    public function __construct(private TrailingBlankRemover $blankTail)
    {
        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements()
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height', 'loading'])
            // The narration mark on <audio> and the slideshow mark on <figure> must cross this barrier; class
            // carries no script, so no styling or XSS hole opens.
            ->allowAttribute('class', ['audio', 'figure'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->forceAttribute('a', 'target', '_blank')
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes(['http', 'https'])
            ->withMaxInputLength(self::MAX_INPUT_LENGTH);

        $this->sanitizer = new HtmlSanitizer($config);
    }

    #[WithSpan]
    public function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        // Trim the tail AFTER sanitising, so blanks the sanitiser itself leaves
        // behind — the whitespace around a stripped <script>, say — go with it.
        $clean = $this->blankTail->removeFrom(trim($this->sanitizer->sanitize($html)));

        return $clean === '' ? null : $clean;
    }
}
