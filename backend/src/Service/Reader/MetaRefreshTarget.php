<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\UrlResolver;
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
        $document = HtmlDocumentParser::parseOrNull($html);
        if ($document === null) {
            return null;
        }

        foreach ($document->querySelectorAll('meta[http-equiv]') as $meta) {
            if (strtolower((string) $meta->getAttribute('http-equiv')) !== 'refresh') {
                continue;
            }
            $target = $this->httpTarget((string) $meta->getAttribute('content'), $baseUrl);
            if ($target !== null) {
                return $target;
            }
        }

        return null;
    }

    private function httpTarget(string $content, string $baseUrl): ?string
    {
        $rawTarget = $this->zeroDelayTarget($content);
        if ($rawTarget === null) {
            return null;
        }

        $scheme = parse_url($rawTarget, \PHP_URL_SCHEME);
        if (\is_string($scheme)) {
            return \in_array(strtolower($scheme), ['http', 'https'], true) ? $rawTarget : null;
        }
        if ($scheme === false) {
            return null;
        }

        try {
            return UrlResolver::resolve($baseUrl, $rawTarget);
        } catch (FetchException) {
            return null;
        }
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
