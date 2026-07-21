<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Sanitizes third-party article HTML before storage. Config lives in code
 * (not framework yaml) so the service is constructible in any test without a
 * container.
 *
 * SECURITY: this is the only barrier between feed-supplied HTML and the SPA,
 * which holds a JWT in localStorage — a stored XSS here is account takeover.
 */
final class EntrySanitizer
{
    private const MAX_INPUT_LENGTH = 150_000;

    private readonly HtmlSanitizerInterface $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements()
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height', 'loading'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->forceAttribute('a', 'target', '_blank')
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes(['http', 'https'])
            ->withMaxInputLength(self::MAX_INPUT_LENGTH);

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $clean = trim($this->sanitizer->sanitize($html));

        return $clean === '' ? null : $clean;
    }
}
