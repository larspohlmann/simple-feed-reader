<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Exception;

/** There is no pending or running run to stop: the account is already idle. */
final class NoActiveRecommendationRunException extends \RuntimeException
{
}
