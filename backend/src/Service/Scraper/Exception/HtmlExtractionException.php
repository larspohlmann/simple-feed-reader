<?php

declare(strict_types=1);

namespace App\Service\Scraper\Exception;

use App\Service\Parser\Exception\FeedParseException;

/**
 * No article list could be extracted from the page. Extends FeedParseException
 * so the refresh pipeline's existing parse-failure handling applies unchanged.
 */
final class HtmlExtractionException extends FeedParseException
{
}
