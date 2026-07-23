<?php

declare(strict_types=1);

namespace App\Service\Reader\Exception;

/**
 * Any reason the source page could not be retrieved — SSRF-blocked, oversized,
 * non-2xx, too many redirects, or a transport error. ArticleExtractor maps this
 * to the `fetch` failure reason; the underlying cause is preserved for logs.
 */
final class PageFetchException extends \RuntimeException
{
}
