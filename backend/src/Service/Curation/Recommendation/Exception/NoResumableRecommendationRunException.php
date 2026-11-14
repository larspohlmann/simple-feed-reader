<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Exception;

/** There is no failed run to resume: the latest run is missing, or it is in
 *  some state other than failed. The caller should start a fresh run instead. */
final class NoResumableRecommendationRunException extends \RuntimeException
{
}
