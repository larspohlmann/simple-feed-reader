<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Fetch\PageUrls;
use App\Service\Html\HtmlDocumentParser;

/**
 * The target of a client-side redirect a landed page performs with a zero-delay
 * <meta http-equiv="refresh">. A timed reload (a non-zero delay) is a real reload,
 * not a redirect, so it yields null — as does a missing or non-http(s) target.
 */
final readonly class MetaRefreshTarget
{
    public function within(string $html, string $baseUrl): ?string
    {
        // Almost every landed page is an ordinary article with no refresh meta;
        // skip the DOM parse unless the markup can carry one.
        if (stripos($html, 'http-equiv') === false) {
            return null;
        }

        $document = HtmlDocumentParser::parseOrNull($html);
        if ($document === null) {
            return null;
        }

        $pageUrls = new PageUrls($baseUrl);
        foreach ($document->querySelectorAll('meta[http-equiv]') as $meta) {
            if (strtolower((string) $meta->getAttribute('http-equiv')) !== 'refresh') {
                continue;
            }
            $target = $pageUrls->httpUrl($this->zeroDelayTarget((string) $meta->getAttribute('content')));
            if ($target !== null) {
                return $target;
            }
        }

        return null;
    }

    private function zeroDelayTarget(string $content): ?string
    {
        $parts = explode(';', $content, 2);
        if (\count($parts) !== 2 || !is_numeric(trim($parts[0])) || (float) trim($parts[0]) !== 0.0) {
            return null;
        }

        $target = trim((string) preg_replace('/^\s*url\s*=\s*/i', '', trim($parts[1])), "\"'");

        return $target === '' ? null : $target;
    }
}
