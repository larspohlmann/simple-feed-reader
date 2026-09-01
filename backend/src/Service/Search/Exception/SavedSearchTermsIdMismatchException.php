<?php

declare(strict_types=1);

namespace App\Service\Search\Exception;

/**
 * SavedSearchTermsWithIds pairs each saved search's terms with its id by
 * position; a caller that built the two lists separately and let them drift
 * apart would silently mislabel every entry after the first mismatch.
 */
final class SavedSearchTermsIdMismatchException extends \LogicException
{
}
